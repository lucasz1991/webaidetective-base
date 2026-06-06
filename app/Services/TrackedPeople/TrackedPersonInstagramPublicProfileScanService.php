<?php

namespace App\Services\TrackedPeople;

use App\Exceptions\TrackedPersonInstagramScanCancelledException;
use App\Models\InstagramProfileRelationship;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramInferredConnection;
use App\Models\TrackedPersonInstagramPublicProfileScan;
use App\Models\TrackedPersonInstagramPublicProfileScanLog;
use App\Models\TrackedPersonPublicProfile;
use App\Services\Billing\ScanCreditService;
use App\Services\Social\InstagramScraper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TrackedPersonInstagramPublicProfileScanService
{
    private const STATUS_MESSAGE_MAX_LENGTH = 240;

    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly TrackedPersonInstagramScanCoordinator $scanCoordinator,
        private readonly InstagramProfileRelationshipStore $profileRelationshipStore,
        private readonly ScanCreditService $scanCreditService,
    ) {
    }

    private ?array $activeScanControl = null;

    public function scan(TrackedPerson $trackedPerson, ?callable $progress = null, ?int $onlyPublicProfileId = null): Collection
    {
        $targetUsername = $this->scraper->normalizeInstagramUsername($trackedPerson->instagram_username);

        if ($targetUsername === null) {
            throw new \RuntimeException('Fuer diese Person ist kein Instagram-Name hinterlegt.');
        }

        $scanControl = $this->scanCoordinator->begin(
            $trackedPerson->id,
            'Public-Profile-Verbindungsscan',
        );

        Cache::lock('tracked-person-instagram-public-profile-scan:'.$trackedPerson->id, 3600)->forceRelease();
        $lock = Cache::lock('tracked-person-instagram-public-profile-scan:'.$trackedPerson->id, 3600);

        if (! $lock->get()) {
            $this->scanCoordinator->finish($trackedPerson->id, (int) $scanControl['generation']);
            throw new \RuntimeException('Fuer diese Person laeuft bereits ein Public-Profile-Verbindungsscan.');
        }

        $this->activeScanControl = $scanControl;

        try {
            return $this->scanWithLock($trackedPerson, $targetUsername, $progress, $onlyPublicProfileId);
        } finally {
            $lock->release();
            $this->scanCoordinator->finish($trackedPerson->id, (int) $scanControl['generation']);
            $this->activeScanControl = null;
        }
    }

    private function scanWithLock(
        TrackedPerson $trackedPerson,
        string $targetUsername,
        ?callable $progress = null,
        ?int $onlyPublicProfileId = null,
    ): Collection
    {
        $publicProfiles = $trackedPerson->publicProfiles()
            ->where('platform', 'instagram')
            ->where('is_public', true)
            ->whereNotNull('username')
            ->when($onlyPublicProfileId, fn ($query) => $query->whereKey($onlyPublicProfileId))
            ->latest()
            ->get();

        if ($publicProfiles->isEmpty()) {
            throw new \RuntimeException('Es sind keine bekannten oeffentlichen Instagram-Profile hinterlegt.');
        }

        $createdScans = collect();
        $total = max(1, $publicProfiles->count());
        $liveInferredFollowers = [];
        $liveInferredFollowing = [];
        $pausedForRateLimit = false;
        $stoppedByUser = false;

        $this->reportProgress($progress, [
            'phase' => 'public-connections',
            'percent' => 1,
            'message' => 'Public-Profile-Verbindungsscan wird vorbereitet.',
            'foundFollowers' => 0,
            'foundFollowing' => 0,
            'inferredFollowers' => [],
            'inferredFollowing' => [],
        ]);

        foreach ($publicProfiles->values() as $index => $publicProfile) {
            if ($this->shouldStopGracefully()) {
                $stoppedByUser = true;
                break;
            }

            $publicUsername = $this->scraper->normalizeInstagramUsername($publicProfile->username);

            if ($publicUsername === null) {
                $createdScans->push($this->storeFailedScan(
                    $trackedPerson,
                    $publicProfile,
                    $targetUsername,
                    (string) $publicProfile->username,
                    'Oeffentliches Profil hat keinen gueltigen Instagram-Username.',
                ));

                continue;
            }

            $this->profileRelationshipStore->syncTrackedPersonProfile($trackedPerson);
            $this->profileRelationshipStore->syncPublicProfile($publicProfile, [
                'display_name' => $publicProfile->display_name,
                'profile_url' => 'https://www.instagram.com/'.$publicUsername.'/',
            ]);

            $candidates = $this->buildStoredCandidateList($trackedPerson, $publicProfile, $publicUsername);

            if ($candidates === []) {
                $createdScans->push($this->storeFailedScan(
                    $trackedPerson,
                    $publicProfile,
                    $targetUsername,
                    $publicUsername,
                    'Fuer dieses bekannte Profil wurden keine gespeicherten Follower-/Gefolgt-Listen gefunden.',
                ));

                continue;
            }

            $profileStart = (int) floor(($index / $total) * 100);
            $profileEnd = (int) floor((($index + 1) / $total) * 100);
            $scan = $this->startOrResumeProgressScan($trackedPerson, $publicProfile, $targetUsername, $publicUsername, $candidates);
            $resumePayload = $scan->raw_payload ?? [];
            $resumeCheckedCandidateUsernames = $this->filterCandidateUsernamesForCandidates(
                $this->normalizeCandidateUsernames(data_get($resumePayload, 'checkedCandidateUsernames', [])),
                $candidates,
            );
            $resumeProfileInferredFollowers = $this->normalizeProgressConnectionItems(data_get($resumePayload, 'inferredFollowers', []), $publicUsername);
            $resumeProfileInferredFollowing = $this->normalizeProgressConnectionItems(data_get($resumePayload, 'inferredFollowing', []), $publicUsername);

            $liveInferredFollowers = $this->mergeProgressConnectionItems($liveInferredFollowers, $resumeProfileInferredFollowers);
            $liveInferredFollowing = $this->mergeProgressConnectionItems($liveInferredFollowing, $resumeProfileInferredFollowing);

            $this->reportProgress($progress, [
                'phase' => 'public-connections',
                'percent' => max(1, $profileStart),
                'message' => 'Verbindung mit @'.$publicUsername.' wird geprueft.',
                'foundFollowers' => count($liveInferredFollowers),
                'foundFollowing' => count($liveInferredFollowing),
                'inferredFollowers' => $liveInferredFollowers,
                'inferredFollowing' => $liveInferredFollowing,
            ]);

            try {
                $currentProfileInferredFollowers = [];
                $currentProfileInferredFollowing = [];
                $scanPausedForRateLimit = false;

                $payload = $this->scraper->scanPublicProfileConnection(
                    $publicUsername,
                    $targetUsername,
                    function (array $state) use (
                        $progress,
                        $trackedPerson,
                        $publicProfile,
                        $scan,
                        $targetUsername,
                        $publicUsername,
                        $profileStart,
                        $profileEnd,
                        $candidates,
                        &$resumeCheckedCandidateUsernames,
                        &$resumeProfileInferredFollowers,
                        &$resumeProfileInferredFollowing,
                        &$liveInferredFollowers,
                        &$liveInferredFollowing,
                        &$currentProfileInferredFollowers,
                        &$currentProfileInferredFollowing,
                        &$scanPausedForRateLimit,
                    ): void {
                        if (array_key_exists('inferredFollowers', $state)) {
                            $currentProfileInferredFollowers = $this->normalizeProgressConnectionItems(
                                $state['inferredFollowers'],
                                $publicUsername,
                            );
                        }

                        if (array_key_exists('inferredFollowing', $state)) {
                            $currentProfileInferredFollowing = $this->normalizeProgressConnectionItems(
                                $state['inferredFollowing'],
                                $publicUsername,
                            );
                        }

                        $profileReportedFollowers = $this->mergeProgressConnectionItems(
                            $resumeProfileInferredFollowers,
                            $currentProfileInferredFollowers,
                        );
                        $profileReportedFollowing = $this->mergeProgressConnectionItems(
                            $resumeProfileInferredFollowing,
                            $currentProfileInferredFollowing,
                        );
                        $reportedFollowers = $this->mergeProgressConnectionItems(
                            $liveInferredFollowers,
                            $profileReportedFollowers,
                        );
                        $reportedFollowing = $this->mergeProgressConnectionItems(
                            $liveInferredFollowing,
                            $profileReportedFollowing,
                        );
                        $scanPausedForRateLimit = $scanPausedForRateLimit || (bool) ($state['stoppedForRateLimit'] ?? false);

                        $this->persistProgressScanState(
                            $trackedPerson,
                            $publicProfile,
                            $scan,
                            $targetUsername,
                            $publicUsername,
                            $state,
                            $candidates,
                            $profileReportedFollowers,
                            $profileReportedFollowing,
                        );

                        $this->reportProgress($progress, [
                            'phase' => 'public-connections',
                            'percent' => $profileStart + (int) floor(((int) ($state['percent'] ?? 0) / 100) * max(1, $profileEnd - $profileStart)),
                            'loaded' => $state['loaded'] ?? null,
                            'expected' => $state['expected'] ?? null,
                            'foundFollowers' => count($reportedFollowers),
                            'foundFollowing' => count($reportedFollowing),
                            'rateLimitedCandidates' => $state['rateLimitedCandidates'] ?? null,
                            'message' => (string) ($state['message'] ?? 'Verbindung mit @'.$publicUsername.' wird geprueft.'),
                            'inferredFollowers' => $reportedFollowers,
                            'inferredFollowing' => $reportedFollowing,
                            'scraperProfileLabel' => $state['scraperProfileLabel'] ?? null,
                            'scraperProfileLoginUsername' => $state['scraperProfileLoginUsername'] ?? null,
                            'scraperProfileId' => $state['scraperProfileId'] ?? null,
                            'scraperProfileSwitchTarget' => $state['scraperProfileSwitchTarget'] ?? null,
                        ]);
                    },
                    $this->withActiveScanControl([
                        'publicConnectionCandidates' => $candidates,
                        'publicConnectionSkipCandidateUsernames' => $resumeCheckedCandidateUsernames,
                    ]),
                );

                $freshScanPayload = $scan->fresh()?->raw_payload ?? [];
                $payload = $this->mergeFinalPayloadWithProgress(
                    is_array($freshScanPayload) ? $freshScanPayload : [],
                    $payload,
                    $publicUsername,
                    $candidates,
                );
                $liveInferredFollowers = $this->mergeProgressConnectionItems(
                    $liveInferredFollowers,
                    $this->normalizeProgressConnectionItems($payload['inferredFollowers'] ?? [], $publicUsername),
                );
                $liveInferredFollowing = $this->mergeProgressConnectionItems(
                    $liveInferredFollowing,
                    $this->normalizeProgressConnectionItems($payload['inferredFollowing'] ?? [], $publicUsername),
                );
                $createdScans->push($this->storeScan($trackedPerson, $publicProfile, $payload, $scan));

                $this->reportProgress($progress, [
                    'phase' => 'public-connections',
                    'percent' => max(1, $profileEnd),
                    'message' => ((bool) ($payload['stoppedForRateLimit'] ?? false))
                        ? 'Verbindung mit @'.$publicUsername.' wegen Instagram-Rate-Limit pausiert.'
                        : 'Verbindung mit @'.$publicUsername.' abgeschlossen.',
                    'foundFollowers' => count($liveInferredFollowers),
                    'foundFollowing' => count($liveInferredFollowing),
                    'inferredFollowers' => $liveInferredFollowers,
                    'inferredFollowing' => $liveInferredFollowing,
                ]);

                if ((bool) ($payload['gracefullyStopped'] ?? false)) {
                    $stoppedByUser = true;
                    break;
                }

                if ((bool) ($payload['stoppedForRateLimit'] ?? false) || $scanPausedForRateLimit) {
                    $pausedForRateLimit = true;
                    break;
                }
            } catch (TrackedPersonInstagramScanCancelledException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $createdScans->push($this->storeFailedScan(
                    $trackedPerson,
                    $publicProfile,
                    $targetUsername,
                    $publicUsername,
                    $exception->getMessage(),
                    $scan,
                ));
            }
        }

        $this->reportProgress($progress, [
            'phase' => 'done',
            'percent' => 100,
            'message' => $stoppedByUser
                ? 'Public-Profile-Verbindungsscan wurde beendet. Bisherige Treffer und Kandidatenfortschritt wurden gespeichert.'
                : ($pausedForRateLimit
                ? 'Public-Profile-Verbindungsscan wegen Instagram-Rate-Limit pausiert. Spaeter erneut starten zum Fortsetzen.'
                : 'Public-Profile-Verbindungsscan abgeschlossen.'),
            'foundFollowers' => count($liveInferredFollowers),
            'foundFollowing' => count($liveInferredFollowing),
            'inferredFollowers' => $liveInferredFollowers,
            'inferredFollowing' => $liveInferredFollowing,
        ]);

        return $createdScans->values();
    }

    private function buildStoredCandidateList(
        TrackedPerson $trackedPerson,
        TrackedPersonPublicProfile $publicProfile,
        string $publicUsername,
    ): array {
        $linkedTrackedPerson = TrackedPerson::query()
            ->where('user_id', $trackedPerson->user_id)
            ->where('id', '!=', $trackedPerson->id)
            ->get()
            ->first(function (TrackedPerson $candidate) use ($publicUsername): bool {
                $candidateUsername = $this->scraper->normalizeInstagramUsername($candidate->instagram_username);

                return $candidateUsername === $publicUsername;
            });

        if (! $linkedTrackedPerson) {
            return [];
        }

        $relationalCandidates = $this->buildStoredCandidateListFromProfileRelationships($linkedTrackedPerson, $publicUsername);

        if ($relationalCandidates !== []) {
            return $relationalCandidates;
        }

        $snapshots = $linkedTrackedPerson
            ->instagramSnapshots()
            ->latest('analyzed_at')
            ->limit(20)
            ->get();

        if ($snapshots->isEmpty()) {
            return [];
        }

        $candidates = [];

        foreach ([
            'followersList' => 'known_profile_followers',
            'followingList' => 'known_profile_following',
        ] as $payloadKey => $sourceList) {
            foreach ($this->loadLatestActiveRelationshipItems($snapshots, $payloadKey) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $rawUsername = $item['username'] ?? null;

                if (! is_scalar($rawUsername)) {
                    continue;
                }

                $username = $this->scraper->normalizeInstagramUsername((string) $rawUsername);

                if ($username === null || $username === $publicUsername) {
                    continue;
                }

                $profileVisibility = $this->nullableProfileVisibility(
                    $item['profileVisibility'] ?? data_get($item, 'hoverCard.profileVisibility'),
                );
                $isPrivate = is_bool($item['isPrivate'] ?? null)
                    ? $item['isPrivate']
                    : (is_bool(data_get($item, 'hoverCard.isPrivate')) ? data_get($item, 'hoverCard.isPrivate') : null);
                $profileImageUrl = $this->nullableTrim($item['profileImageUrl'] ?? $item['profile_image_url'] ?? null);
                $postsCount = $this->nullableInteger($item['postsCount'] ?? data_get($item, 'hoverCard.postsCount'));
                $followersCount = $this->nullableInteger($item['followersCount'] ?? data_get($item, 'hoverCard.followersCount'));
                $followingCount = $this->nullableInteger($item['followingCount'] ?? data_get($item, 'hoverCard.followingCount'));
                $hoverCard = is_array($item['hoverCard'] ?? null) ? $item['hoverCard'] : null;

                $existing = $candidates[$username] ?? [
                    'username' => $username,
                    'displayName' => $this->nullableTrim($item['displayName'] ?? null),
                    'profileUrl' => $this->nullableTrim($item['profileUrl'] ?? null) ?: 'https://www.instagram.com/'.$username.'/',
                    'profileImageUrl' => $profileImageUrl,
                    'profileVisibility' => $profileVisibility,
                    'isPrivate' => $isPrivate,
                    'postsCount' => $postsCount,
                    'followersCount' => $followersCount,
                    'followingCount' => $followingCount,
                    'hoverCard' => $hoverCard,
                    'sourceLists' => [],
                ];

                $existing['profileImageUrl'] ??= $profileImageUrl;
                $existing['profileVisibility'] ??= $profileVisibility;
                $existing['isPrivate'] ??= $isPrivate;
                $existing['postsCount'] ??= $postsCount;
                $existing['followersCount'] ??= $followersCount;
                $existing['followingCount'] ??= $followingCount;
                $existing['hoverCard'] ??= $hoverCard;

                if (! in_array($sourceList, $existing['sourceLists'], true)) {
                    $existing['sourceLists'][] = $sourceList;
                }

                $candidates[$username] = $existing;
            }
        }

        return array_values($candidates);
    }

    private function buildStoredCandidateListFromProfileRelationships(
        TrackedPerson $linkedTrackedPerson,
        string $publicUsername,
    ): array {
        if (
            ! Schema::hasTable('instagram_profile_relationships')
            || ! Schema::hasTable('instagram_profiles')
            || ! $linkedTrackedPerson->current_instagram_profile_id
        ) {
            return [];
        }

        $relationships = InstagramProfileRelationship::query()
            ->with('relatedInstagramProfile')
            ->where('source_instagram_profile_id', $linkedTrackedPerson->current_instagram_profile_id)
            ->where('status', 'active')
            ->whereIn('list_type', ['followers', 'following'])
            ->get();

        if ($relationships->isEmpty()) {
            return [];
        }

        $candidates = [];

        foreach ($relationships as $relationship) {
            $relatedProfile = $relationship->relatedInstagramProfile;
            $username = $this->scraper->normalizeInstagramUsername($relatedProfile?->username);

            if ($username === null || $username === $publicUsername) {
                continue;
            }

            $sourceList = $relationship->list_type === 'followers'
                ? 'known_profile_followers'
                : 'known_profile_following';
            $existing = $candidates[$username] ?? [
                'username' => $username,
                'displayName' => $this->nullableTrim($relationship->display_name_snapshot)
                    ?: $this->nullableTrim($relatedProfile?->display_name)
                    ?: $this->nullableTrim($relatedProfile?->full_name),
                'profileUrl' => $this->nullableTrim($relationship->profile_url_snapshot)
                    ?: $this->nullableTrim($relatedProfile?->profile_url)
                    ?: 'https://www.instagram.com/'.$username.'/',
                'sourceLists' => [],
            ];

            if (! in_array($sourceList, $existing['sourceLists'], true)) {
                $existing['sourceLists'][] = $sourceList;
            }

            $candidates[$username] = $existing;
        }

        return array_values($candidates);
    }

    private function startOrResumeProgressScan(
        TrackedPerson $trackedPerson,
        TrackedPersonPublicProfile $publicProfile,
        string $targetUsername,
        string $publicUsername,
        array $candidates,
    ): TrackedPersonInstagramPublicProfileScan {
        $resumableScan = TrackedPersonInstagramPublicProfileScan::query()
            ->where('tracked_person_id', $trackedPerson->id)
            ->where('public_profile_id', $publicProfile->id)
            ->where('target_username', Str::lower($targetUsername))
            ->where('public_username', Str::lower($publicUsername))
            ->latest('updated_at')
            ->limit(10)
            ->get()
            ->first(function (TrackedPersonInstagramPublicProfileScan $scan): bool {
                $payload = is_array($scan->raw_payload ?? null) ? $scan->raw_payload : [];

                return (bool) data_get($payload, 'isResumable', false)
                    && in_array(data_get($payload, 'progressStatus'), ['in_progress', 'rate_limited', 'stopped'], true);
            });

        if ($resumableScan) {
            $payload = is_array($resumableScan->raw_payload ?? null) ? $resumableScan->raw_payload : [];
            $resumableScan->forceFill([
                'status_level' => 'partial',
                'status_message' => 'Verbindungsscan wird ab gespeichertem Kandidatenstand fortgesetzt.',
                'raw_payload' => [
                    ...$payload,
                    'progressStatus' => 'in_progress',
                    'isResumable' => true,
                    'resumedAt' => now('UTC')->toISOString(),
                    'candidatesTotal' => count($candidates),
                    'candidatesChecked' => count($this->filterCandidateUsernamesForCandidates(
                        $this->normalizeCandidateUsernames(data_get($payload, 'checkedCandidateUsernames', [])),
                        $candidates,
                    )),
                ],
                'analyzed_at' => now('UTC'),
            ])->save();

            return $resumableScan->fresh();
        }

        return TrackedPersonInstagramPublicProfileScan::create([
            'tracked_person_id' => $trackedPerson->id,
            'public_profile_id' => $publicProfile->id,
            'user_id' => $trackedPerson->user_id,
            'target_username' => Str::lower($targetUsername),
            'public_username' => Str::lower($publicUsername),
            'relation_type' => 'candidate_search',
            'status_level' => 'partial',
            'status_message' => 'Verbindungsscan laeuft; Kandidatenfortschritt wird gespeichert.',
            'raw_payload' => [
                'ok' => false,
                'progressStatus' => 'in_progress',
                'isResumable' => true,
                'relationType' => 'candidate_search',
                'targetUsername' => Str::lower($targetUsername),
                'publicUsername' => Str::lower($publicUsername),
                'candidatesTotal' => count($candidates),
                'candidatesChecked' => 0,
                'checkedCandidateUsernames' => [],
                'inferredFollowers' => [],
                'inferredFollowing' => [],
                'checkedPreview' => [],
                'startedAt' => now('UTC')->toISOString(),
            ],
            'analyzed_at' => now('UTC'),
        ]);
    }

    private function persistProgressScanState(
        TrackedPerson $trackedPerson,
        TrackedPersonPublicProfile $publicProfile,
        TrackedPersonInstagramPublicProfileScan $scan,
        string $targetUsername,
        string $publicUsername,
        array $state,
        array $candidates,
        array $inferredFollowers,
        array $inferredFollowing,
    ): void {
        $this->assertActiveScanCurrent();

        $freshScan = $scan->fresh();
        $payload = is_array($freshScan?->raw_payload) ? $freshScan->raw_payload : [];
        $checkedCandidateUsernames = $this->filterCandidateUsernamesForCandidates(
            $this->normalizeCandidateUsernames(data_get($payload, 'checkedCandidateUsernames', [])),
            $candidates,
        );
        $candidateUsername = $this->scraper->normalizeInstagramUsername((string) ($state['candidateUsername'] ?? ''));
        $stage = (string) ($state['stage'] ?? '');
        $stoppedForRateLimit = (bool) ($state['stoppedForRateLimit'] ?? false);
        $gracefullyStopped = (bool) ($state['gracefullyStopped'] ?? false) || $stage === 'scan-stop-requested';

        if ($stage === 'candidate-scan-complete' && $candidateUsername !== null && ! in_array($candidateUsername, $checkedCandidateUsernames, true)) {
            $checkedCandidateUsernames[] = $candidateUsername;
        }

        $checkedPreview = $this->mergeCheckedConnectionPreviews(
            data_get($payload, 'checkedPreview', []),
            is_array($state['candidateConnection'] ?? null) && $stage === 'candidate-scan-complete'
                ? [$state['candidateConnection']]
                : [],
        );
        $candidateErrorScreenshots = array_values(array_filter(data_get($payload, 'candidateErrorScreenshots', []), 'is_array'));
        $progressStatus = $gracefullyStopped ? 'stopped' : ($stoppedForRateLimit ? 'rate_limited' : 'in_progress');
        $statusMessage = $gracefullyStopped
            ? 'Verbindungsscan wurde beendet. Der Kandidatenfortschritt wurde gespeichert.'
            : ($stoppedForRateLimit
                ? 'Verbindungsscan wegen Instagram-Rate-Limit pausiert. Er kann spaeter fortgesetzt werden.'
                : (string) ($state['message'] ?? 'Verbindungsscan laeuft; Kandidatenfortschritt wird gespeichert.'));
        $statusInfo = $this->buildStoredStatusMessage($statusMessage, includeDetailNotice: false);

        $payload = [
            ...$payload,
            'ok' => false,
            'progressStatus' => $progressStatus,
            'isResumable' => true,
            'relationType' => 'candidate_search',
            'targetUsername' => Str::lower($targetUsername),
            'publicUsername' => Str::lower($publicUsername),
            'candidatesTotal' => count($candidates),
            'candidatesChecked' => count($checkedCandidateUsernames),
            'checkedCandidateUsernames' => $checkedCandidateUsernames,
            'candidateErrorScreenshots' => $candidateErrorScreenshots,
            'checkedPreview' => $checkedPreview,
            'inferredFollowers' => $inferredFollowers,
            'inferredFollowing' => $inferredFollowing,
            'foundFollowers' => count($inferredFollowers),
            'foundFollowing' => count($inferredFollowing),
            'lastProgressStage' => $stage,
            'lastProgressMessage' => $statusInfo['summary'],
            'lastProgressAt' => now('UTC')->toISOString(),
            'stoppedForRateLimit' => $stoppedForRateLimit,
            'gracefullyStopped' => $gracefullyStopped,
            'rateLimitedCandidateUsername' => $stoppedForRateLimit ? $candidateUsername : data_get($payload, 'rateLimitedCandidateUsername'),
            'stoppedAt' => $gracefullyStopped ? now('UTC')->toISOString() : data_get($payload, 'stoppedAt'),
        ];
        $payload = $this->normalizePayloadScreenshotPaths($payload);

        $scan->forceFill([
            'status_level' => 'partial',
            'status_message' => $statusInfo['inline'],
            'raw_payload' => $payload,
            'analyzed_at' => now('UTC'),
        ])->save();

        $this->storeInferredConnections($trackedPerson, $publicProfile, $scan, $payload, now('UTC'));
    }

    private function mergeFinalPayloadWithProgress(
        array $progressPayload,
        array $finalPayload,
        string $publicUsername,
        array $candidates,
    ): array {
        $checkedCandidateUsernames = $this->filterCandidateUsernamesForCandidates($this->normalizeCandidateUsernames([
            ...$this->normalizeCandidateUsernames(data_get($progressPayload, 'checkedCandidateUsernames', [])),
            ...$this->normalizeCandidateUsernames(data_get($finalPayload, 'checkedCandidateUsernames', [])),
        ]), $candidates);
        $inferredFollowers = $this->mergeProgressConnectionItems(
            $this->normalizeProgressConnectionItems(data_get($progressPayload, 'inferredFollowers', []), $publicUsername),
            $this->normalizeProgressConnectionItems(data_get($finalPayload, 'inferredFollowers', []), $publicUsername),
        );
        $inferredFollowing = $this->mergeProgressConnectionItems(
            $this->normalizeProgressConnectionItems(data_get($progressPayload, 'inferredFollowing', []), $publicUsername),
            $this->normalizeProgressConnectionItems(data_get($finalPayload, 'inferredFollowing', []), $publicUsername),
        );
        $stoppedForRateLimit = (bool) ($finalPayload['stoppedForRateLimit'] ?? data_get($progressPayload, 'stoppedForRateLimit', false));
        $gracefullyStopped = (bool) ($finalPayload['gracefullyStopped'] ?? data_get($progressPayload, 'gracefullyStopped', false));
        $progressCandidateErrorScreenshots = data_get($progressPayload, 'candidateErrorScreenshots', []);
        $finalCandidateErrorScreenshots = data_get($finalPayload, 'candidateErrorScreenshots', []);
        $progressCandidateErrorScreenshots = is_array($progressCandidateErrorScreenshots) ? $progressCandidateErrorScreenshots : [];
        $finalCandidateErrorScreenshots = is_array($finalCandidateErrorScreenshots) ? $finalCandidateErrorScreenshots : [];

        return [
            ...$progressPayload,
            ...$finalPayload,
            'progressStatus' => $gracefullyStopped ? 'stopped' : ($stoppedForRateLimit ? 'rate_limited' : 'completed'),
            'isResumable' => $gracefullyStopped || $stoppedForRateLimit,
            'candidatesTotal' => count($candidates),
            'candidatesChecked' => count($checkedCandidateUsernames),
            'checkedCandidateUsernames' => $checkedCandidateUsernames,
            'checkedPreview' => $this->mergeCheckedConnectionPreviews(
                data_get($progressPayload, 'checkedPreview', []),
                data_get($finalPayload, 'checkedPreview', []),
            ),
            'candidateErrorScreenshots' => array_values(array_filter([
                ...$progressCandidateErrorScreenshots,
                ...$finalCandidateErrorScreenshots,
            ], 'is_array')),
            'inferredFollowers' => $inferredFollowers,
            'inferredFollowing' => $inferredFollowing,
            'foundFollowers' => count($inferredFollowers),
            'foundFollowing' => count($inferredFollowing),
            'stoppedForRateLimit' => $stoppedForRateLimit,
            'gracefullyStopped' => $gracefullyStopped,
            'completedAt' => $stoppedForRateLimit || $gracefullyStopped ? null : now('UTC')->toISOString(),
            'pausedAt' => $stoppedForRateLimit ? now('UTC')->toISOString() : data_get($progressPayload, 'pausedAt'),
            'stoppedAt' => $gracefullyStopped ? now('UTC')->toISOString() : data_get($progressPayload, 'stoppedAt'),
        ];
    }

    private function normalizeCandidateUsernames(mixed $candidateUsernames): array
    {
        if (! is_array($candidateUsernames)) {
            return [];
        }

        $normalizedUsernames = [];

        foreach ($candidateUsernames as $candidateUsername) {
            if (! is_scalar($candidateUsername)) {
                continue;
            }

            $normalizedUsername = $this->scraper->normalizeInstagramUsername((string) $candidateUsername);

            if ($normalizedUsername !== null) {
                $normalizedUsernames[$normalizedUsername] = $normalizedUsername;
            }
        }

        return array_values($normalizedUsernames);
    }

    private function filterCandidateUsernamesForCandidates(array $candidateUsernames, array $candidates): array
    {
        $candidateUsernameLookup = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || ! is_scalar($candidate['username'] ?? null)) {
                continue;
            }

            $username = $this->scraper->normalizeInstagramUsername((string) $candidate['username']);

            if ($username !== null) {
                $candidateUsernameLookup[$username] = true;
            }
        }

        return array_values(array_filter(
            $candidateUsernames,
            static fn (string $candidateUsername): bool => isset($candidateUsernameLookup[$candidateUsername]),
        ));
    }

    private function mergeCheckedConnectionPreviews(mixed ...$connectionGroups): array
    {
        $connections = [];

        foreach ($connectionGroups as $connectionGroup) {
            if (! is_array($connectionGroup)) {
                continue;
            }

            foreach ($connectionGroup as $connection) {
                if (! is_array($connection) || ! is_scalar($connection['username'] ?? null)) {
                    continue;
                }

                $username = $this->scraper->normalizeInstagramUsername((string) $connection['username']);

                if ($username === null) {
                    continue;
                }

                $connections[$username] = [
                    ...$connection,
                    'username' => $username,
                ];
            }
        }

        return array_slice(array_values($connections), -100);
    }

    private function loadLatestActiveRelationshipItems(Collection $snapshots, string $payloadKey): array
    {
        foreach ($snapshots as $snapshot) {
            $rawPayload = is_array($snapshot->raw_payload ?? null) ? $snapshot->raw_payload : [];

            if (! $this->hasStoredRelationshipList($rawPayload, $payloadKey)) {
                continue;
            }

            return $this->loadStoredRelationshipItems($rawPayload, $payloadKey);
        }

        return [];
    }

    private function hasStoredRelationshipList(array $rawPayload, string $payloadKey): bool
    {
        $relationshipList = data_get($rawPayload, 'extractedProfile.'.$payloadKey);

        if (! is_array($relationshipList) || $relationshipList === []) {
            return false;
        }

        foreach (['itemsPath', 'activeItems', 'items', 'observedItems', 'itemsPreview', 'observedPreview', 'activeCount', 'count', 'knownCount', 'observedCount'] as $key) {
            if (array_key_exists($key, $relationshipList)) {
                return true;
            }
        }

        return false;
    }

    private function loadStoredRelationshipItems(array $rawPayload, string $payloadKey): array
    {
        $relationshipList = data_get($rawPayload, 'extractedProfile.'.$payloadKey, []);

        if (! is_array($relationshipList)) {
            return [];
        }

        $itemsPath = data_get($relationshipList, 'itemsPath');

        if (is_string($itemsPath) && $itemsPath !== '' && Storage::disk('public')->exists($itemsPath)) {
            try {
                $decoded = json_decode(Storage::disk('public')->get($itemsPath), true, flags: JSON_THROW_ON_ERROR);

                if (! is_array($decoded)) {
                    return [];
                }

                return $this->loadActiveItemsFromRelationshipPayload($decoded);
            } catch (\Throwable) {
                return [];
            }
        }

        return $this->loadActiveItemsFromRelationshipPayload($relationshipList);
    }

    private function loadActiveItemsFromRelationshipPayload(array $payload): array
    {
        foreach (['activeItems', 'observedItems', 'observedPreview'] as $key) {
            if (array_key_exists($key, $payload)) {
                $items = data_get($payload, $key, []);

                return is_array($items) ? $this->filterActiveRelationshipItems($items) : [];
            }
        }

        if (! $this->hasHistoricalRelationshipMarkers($payload)) {
            foreach (['items', 'itemsPreview'] as $key) {
                if (! array_key_exists($key, $payload)) {
                    continue;
                }

                $items = data_get($payload, $key, []);

                return is_array($items) ? $this->filterActiveRelationshipItems($items) : [];
            }
        }

        return [];
    }

    private function hasHistoricalRelationshipMarkers(array $payload): bool
    {
        foreach ([
            'allKnownItems',
            'allKnownCount',
            'removedItems',
            'removedCount',
            'removedHistoryItems',
            'removedHistoryCount',
            'removedHistoryPreview',
            'currentlyRemovedItems',
            'currentlyRemovedCount',
        ] as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }

        return false;
    }

    private function filterActiveRelationshipItems(array $items): array
    {
        return collect($items)
            ->filter(function ($item): bool {
                if (! is_array($item)) {
                    return false;
                }

                if (filled($item['removedAt'] ?? null)) {
                    return false;
                }

                $status = Str::lower((string) ($item['status'] ?? ''));

                if (in_array($status, ['removed', 'deleted', 'inactive'], true)) {
                    return false;
                }

                return filled($item['username'] ?? null);
            })
            ->values()
            ->all();
    }

    private function storeScan(
        TrackedPerson $trackedPerson,
        TrackedPersonPublicProfile $publicProfile,
        array $payload,
        ?TrackedPersonInstagramPublicProfileScan $existingScan = null,
    ): TrackedPersonInstagramPublicProfileScan {
        $this->assertActiveScanCurrent();

        $payload = $this->normalizePayloadScreenshotPaths($payload);

        $followers = is_array($payload['followers'] ?? null) ? $payload['followers'] : [];
        $following = is_array($payload['following'] ?? null) ? $payload['following'] : [];
        $relationType = $this->normalizeRelationType($payload['relationType'] ?? null);
        $targetFollowsPublic = (bool) ($payload['targetFollowsPublicProfile'] ?? false);
        $publicFollowsTarget = (bool) ($payload['publicProfileFollowsTarget'] ?? false);
        $analyzedAt = now('UTC');

        return DB::transaction(function () use (
            $trackedPerson,
            $publicProfile,
            $payload,
            $followers,
            $following,
            $relationType,
            $targetFollowsPublic,
            $publicFollowsTarget,
            $analyzedAt,
            $existingScan,
        ) {
            $statusInfo = $this->buildStoredStatusMessage(
                (string) ($payload['statusMessage'] ?? 'Verbindungsscan abgeschlossen.'),
            );
            $payload['statusMessage'] = $statusInfo['summary'];
            $payload['statusMessageHasLog'] = $statusInfo['detail'] !== null;

            $scanData = [
                'tracked_person_id' => $trackedPerson->id,
                'public_profile_id' => $publicProfile->id,
                'user_id' => $trackedPerson->user_id,
                'target_username' => Str::lower((string) ($payload['targetUsername'] ?? $trackedPerson->instagram_username)),
                'public_username' => Str::lower((string) ($payload['publicUsername'] ?? $publicProfile->username)),
                'relation_type' => $relationType,
                'public_profile_follows_target' => $publicFollowsTarget,
                'target_follows_public_profile' => $targetFollowsPublic,
                'followers_checked' => (bool) ($followers['checked'] ?? false),
                'followers_available' => (bool) ($followers['available'] ?? false),
                'followers_complete' => (bool) ($followers['complete'] ?? false),
                'followers_observed_count' => $this->nullableInteger($followers['observedCount'] ?? null),
                'followers_expected_count' => $this->nullableInteger($followers['expectedCount'] ?? null),
                'followers_match' => $this->nullableArray($followers['targetItem'] ?? null),
                'following_checked' => (bool) ($following['checked'] ?? false),
                'following_available' => (bool) ($following['available'] ?? false),
                'following_complete' => (bool) ($following['complete'] ?? false),
                'following_observed_count' => $this->nullableInteger($following['observedCount'] ?? null),
                'following_expected_count' => $this->nullableInteger($following['expectedCount'] ?? null),
                'following_match' => $this->nullableArray($following['targetItem'] ?? null),
                'status_level' => (string) ($payload['statusLevel'] ?? 'unknown'),
                'status_message' => $statusInfo['inline'],
                'raw_payload' => $payload,
                'analyzed_at' => $analyzedAt,
            ];

            $scan = $existingScan ?: new TrackedPersonInstagramPublicProfileScan();
            $scan->forceFill($scanData)->save();

            $this->scanCreditService->charge(
                (int) $trackedPerson->user_id,
                $scan,
                $payload,
                'Instagram-Public-Profile-Verbindungsscan @'.$publicProfile->username,
            );

            if ($statusInfo['detail'] !== null) {
                $this->storeScanLog(
                    $scan,
                    'status_detail',
                    (string) ($payload['statusLevel'] ?? 'unknown'),
                    (string) ($payload['lastProgressStage'] ?? $payload['stage'] ?? null),
                    $statusInfo['summary'],
                    $statusInfo['detail'],
                    [
                        'targetUsername' => $payload['targetUsername'] ?? null,
                        'publicUsername' => $payload['publicUsername'] ?? null,
                        'progressStatus' => $payload['progressStatus'] ?? null,
                    ],
                    $analyzedAt,
                );
            }

            $this->storeInferredConnections($trackedPerson, $publicProfile, $scan, $payload, $analyzedAt);

            $relationshipType = $this->mapRelationTypeToPublicProfile($relationType);

            if ($relationshipType !== null) {
                $publicProfile->forceFill([
                    'relationship_type' => $relationshipType,
                ])->save();
            }

            return $scan;
        });
    }

    private function storeFailedScan(
        TrackedPerson $trackedPerson,
        TrackedPersonPublicProfile $publicProfile,
        string $targetUsername,
        string $publicUsername,
        string $errorMessage,
        ?TrackedPersonInstagramPublicProfileScan $existingScan = null,
    ): TrackedPersonInstagramPublicProfileScan {
        $this->assertActiveScanCurrent();

        $errorInfo = $this->buildStoredStatusMessage(
            $errorMessage,
            prefix: 'Verbindungsscan fehlgeschlagen: ',
        );
        $payload = [
            ...($existingScan && is_array($existingScan->raw_payload ?? null) ? $existingScan->raw_payload : []),
            'ok' => false,
            'progressStatus' => 'failed',
            'isResumable' => false,
            'error' => $errorInfo['summary'],
            'errorHasLog' => true,
        ];
        $scanData = [
            'tracked_person_id' => $trackedPerson->id,
            'public_profile_id' => $publicProfile->id,
            'user_id' => $trackedPerson->user_id,
            'target_username' => Str::lower($targetUsername),
            'public_username' => Str::lower($publicUsername),
            'relation_type' => 'unknown',
            'status_level' => 'error',
            'status_message' => $errorInfo['inline'],
            'raw_payload' => $payload,
            'analyzed_at' => now('UTC'),
        ];

        $scan = $existingScan ?: new TrackedPersonInstagramPublicProfileScan();
        $scan->forceFill($scanData)->save();
        $this->storeScanLog(
            $scan,
            'failure',
            'error',
            (string) ($payload['lastProgressStage'] ?? null),
            $errorInfo['summary'],
            $errorMessage,
            [
                'targetUsername' => $targetUsername,
                'publicUsername' => $publicUsername,
                'progressStatus' => 'failed',
            ],
            now('UTC'),
        );

        return $scan;
    }

    private function buildStoredStatusMessage(
        string $message,
        string $prefix = '',
        bool $includeDetailNotice = true,
    ): array {
        $normalized = preg_replace("/\r\n?/", "\n", trim($message)) ?? trim($message);
        $summary = $this->summarizeStatusMessage($normalized);
        $hasDetail = $normalized !== $summary;
        $inline = $prefix.$summary;

        if ($hasDetail && $includeDetailNotice) {
            $inline .= ' Details im Scan-Log gespeichert.';
        }

        return [
            'inline' => $this->limitStatusMessage($inline),
            'summary' => $summary,
            'detail' => $hasDetail ? $normalized : null,
        ];
    }

    private function summarizeStatusMessage(string $message): string
    {
        if ($message === '') {
            return 'Unbekannter Status.';
        }

        $firstLine = trim(Str::before($message, "\n"));
        $summary = preg_replace('/\s+/', ' ', $firstLine) ?? $firstLine;

        foreach (['[SCRAPER PROGRESS]', '[SCRAPER DEBUG]', '[SCRAPER ERROR]'] as $marker) {
            if (str_contains($summary, $marker)) {
                $summary = trim(Str::before($summary, $marker));
            }
        }

        if ($summary === '') {
            $summary = 'Technische Scan-Details wurden gespeichert.';
        }

        return $this->limitStatusMessage($summary);
    }

    private function limitStatusMessage(string $message): string
    {
        return Str::limit(trim($message), self::STATUS_MESSAGE_MAX_LENGTH, '...');
    }

    private function storeScanLog(
        TrackedPersonInstagramPublicProfileScan $scan,
        string $eventType,
        ?string $statusLevel,
        ?string $stage,
        ?string $message,
        ?string $detail,
        ?array $context,
        \Illuminate\Support\Carbon $loggedAt,
    ): void {
        $detail = $this->nullableTrim($detail);

        if ($detail === null) {
            return;
        }

        TrackedPersonInstagramPublicProfileScanLog::create([
            'scan_id' => $scan->id,
            'tracked_person_id' => $scan->tracked_person_id,
            'public_profile_id' => $scan->public_profile_id,
            'user_id' => $scan->user_id,
            'event_type' => $eventType,
            'status_level' => $statusLevel ?: null,
            'stage' => $this->nullableTrim($stage),
            'message' => $this->nullableTrim($message),
            'detail' => $detail,
            'context' => $context ?: null,
            'logged_at' => $loggedAt,
        ]);
    }

    private function normalizePayloadScreenshotPaths(array $payload): array
    {
        if (($payload['screenshotPath'] ?? null) !== null) {
            $payload['screenshotPath'] = $this->normalizePublicScreenshotPath((string) $payload['screenshotPath']);
        }

        if (is_array($payload['candidateErrorScreenshots'] ?? null)) {
            $payload['candidateErrorScreenshots'] = array_values(array_filter(array_map(function ($entry): ?array {
                if (! is_array($entry)) {
                    return null;
                }

                $screenshotPath = $this->normalizePublicScreenshotPath((string) ($entry['screenshotPath'] ?? ''));

                if ($screenshotPath === null) {
                    return null;
                }

                $entry['screenshotPath'] = $screenshotPath;

                return $entry;
            }, $payload['candidateErrorScreenshots'])));
        }

        if (is_array($payload['checkedPreview'] ?? null)) {
            foreach ($payload['checkedPreview'] as $index => $checkedConnection) {
                if (! is_array($checkedConnection) || ! is_array($checkedConnection['debugScreenshotPaths'] ?? null)) {
                    continue;
                }

                $payload['checkedPreview'][$index]['debugScreenshotPaths'] = array_values(array_filter(array_map(
                    fn ($screenshotPath): ?string => is_scalar($screenshotPath)
                        ? $this->normalizePublicScreenshotPath((string) $screenshotPath)
                        : null,
                    $checkedConnection['debugScreenshotPaths'],
                )));
            }
        }

        return $payload;
    }

    private function normalizePublicScreenshotPath(string $rawScreenshotPath): ?string
    {
        if ($rawScreenshotPath === '') {
            return null;
        }

        $resolvedScreenshotPath = $this->scraper->resolvePublicStoragePath($rawScreenshotPath);

        if ($resolvedScreenshotPath !== null) {
            return $resolvedScreenshotPath;
        }

        return Str::contains($rawScreenshotPath, ['\\', ':']) || Str::startsWith($rawScreenshotPath, '/')
            ? null
            : $rawScreenshotPath;
    }

    private function storeInferredConnections(
        TrackedPerson $trackedPerson,
        TrackedPersonPublicProfile $publicProfile,
        TrackedPersonInstagramPublicProfileScan $scan,
        array $payload,
        \Illuminate\Support\Carbon $analyzedAt,
    ): void {
        foreach ([
            'inferredFollowers' => 'follows_target',
            'inferredFollowing' => 'followed_by_target',
        ] as $payloadKey => $relationshipType) {
            $connections = $payload[$payloadKey] ?? [];

            if (! is_array($connections)) {
                continue;
            }

            foreach ($connections as $connection) {
                if (! is_array($connection)) {
                    continue;
                }

                $rawCandidateUsername = $connection['username'] ?? null;

                if (! is_scalar($rawCandidateUsername)) {
                    continue;
                }

                $candidateUsername = $this->scraper->normalizeInstagramUsername((string) $rawCandidateUsername);

                if ($candidateUsername === null) {
                    continue;
                }

                $existing = TrackedPersonInstagramInferredConnection::query()
                    ->where('tracked_person_id', $trackedPerson->id)
                    ->where('public_profile_id', $publicProfile->id)
                    ->where('relationship_type', $relationshipType)
                    ->where('candidate_username', $candidateUsername)
                    ->first();
                $profileColumnData = $this->profileRelationshipStore->profileColumnDataForInferredConnection(
                    $publicProfile,
                    $candidateUsername,
                    [
                        'display_name' => $this->nullableTrim($connection['displayName'] ?? null),
                        'profile_url' => $this->nullableTrim($connection['profileUrl'] ?? null),
                        'profile_image_url' => $this->nullableTrim($connection['profileImageUrl'] ?? $connection['profile_image_url'] ?? null),
                    ],
                );

                TrackedPersonInstagramInferredConnection::updateOrCreate(
                    [
                        'tracked_person_id' => $trackedPerson->id,
                        'public_profile_id' => $publicProfile->id,
                        'relationship_type' => $relationshipType,
                        'candidate_username' => $candidateUsername,
                    ],
                    [
                        'scan_id' => $scan->id,
                        'user_id' => $trackedPerson->user_id,
                        'source_public_username' => Str::lower((string) ($payload['publicUsername'] ?? $publicProfile->username)),
                        'candidate_display_name' => $this->nullableTrim($connection['displayName'] ?? null),
                        'candidate_profile_url' => $this->nullableTrim($connection['profileUrl'] ?? null),
                        'source_lists' => is_array($connection['sourceLists'] ?? null) ? array_values($connection['sourceLists']) : [],
                        'evidence' => $connection,
                        'status' => 'active',
                        'first_seen_at' => $existing?->first_seen_at ?: $analyzedAt,
                        'last_seen_at' => $analyzedAt,
                        ...$profileColumnData,
                    ],
                );
            }
        }
    }

    private function normalizeRelationType(mixed $relationType): string
    {
        $relationType = Str::lower(trim((string) $relationType));

        return in_array($relationType, ['mutual', 'public_follows_target', 'target_follows_public', 'candidate_search', 'none', 'unknown'], true)
            ? $relationType
            : 'unknown';
    }

    private function mapRelationTypeToPublicProfile(string $relationType): ?string
    {
        return match ($relationType) {
            'mutual' => 'mutual',
            'public_follows_target' => 'follows_target',
            'target_follows_public' => 'followed_by_target',
            default => null,
        };
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function nullableProfileVisibility(mixed $value): ?string
    {
        return in_array($value, ['public', 'private', 'unknown'], true) ? $value : null;
    }

    private function nullableArray(mixed $value): ?array
    {
        return is_array($value) && $value !== [] ? $value : null;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeProgressConnectionItems(mixed $connections, string $sourcePublicUsername): array
    {
        if (! is_array($connections)) {
            return [];
        }

        $normalizedConnections = [];
        $sourcePublicUsername = $this->scraper->normalizeInstagramUsername($sourcePublicUsername) ?? Str::lower($sourcePublicUsername);

        foreach (array_slice($connections, 0, 80) as $connection) {
            if (! is_array($connection) || ! is_scalar($connection['username'] ?? null)) {
                continue;
            }

            $candidateUsername = $this->scraper->normalizeInstagramUsername((string) $connection['username']);

            if ($candidateUsername === null) {
                continue;
            }

            $connectionSourceUsername = $this->scraper->normalizeInstagramUsername(
                (string) ($connection['sourcePublicUsername'] ?? $connection['source_public_username'] ?? $sourcePublicUsername),
            ) ?? $sourcePublicUsername;
            $sourceLists = is_array($connection['sourceLists'] ?? null)
                ? array_values(array_unique(array_map(
                    static fn ($sourceList): string => trim((string) $sourceList),
                    array_filter($connection['sourceLists'], 'is_scalar'),
                )))
                : [];

            $normalizedConnections[] = [
                'username' => $candidateUsername,
                'displayName' => $this->nullableTrim($connection['displayName'] ?? $connection['candidate_display_name'] ?? null),
                'profileUrl' => $this->nullableTrim($connection['profileUrl'] ?? $connection['candidate_profile_url'] ?? null)
                    ?: 'https://www.instagram.com/'.$candidateUsername.'/',
                'profileImageUrl' => $this->nullableTrim($connection['profileImageUrl'] ?? $connection['profile_image_url'] ?? null),
                'profileVisibility' => $this->nullableProfileVisibility(
                    $connection['profileVisibility'] ?? data_get($connection, 'hoverCard.profileVisibility'),
                ),
                'isPrivate' => is_bool($connection['isPrivate'] ?? null)
                    ? $connection['isPrivate']
                    : (is_bool(data_get($connection, 'hoverCard.isPrivate')) ? data_get($connection, 'hoverCard.isPrivate') : null),
                'postsCount' => $this->nullableInteger($connection['postsCount'] ?? data_get($connection, 'hoverCard.postsCount')),
                'followersCount' => $this->nullableInteger($connection['followersCount'] ?? data_get($connection, 'hoverCard.followersCount')),
                'followingCount' => $this->nullableInteger($connection['followingCount'] ?? data_get($connection, 'hoverCard.followingCount')),
                'hoverCard' => is_array($connection['hoverCard'] ?? null) ? $connection['hoverCard'] : null,
                'sourcePublicUsername' => $connectionSourceUsername,
                'sourceLists' => array_values(array_filter($sourceLists)),
            ];
        }

        return $normalizedConnections;
    }

    private function mergeProgressConnectionItems(array ...$connectionGroups): array
    {
        $mergedConnections = [];

        foreach ($connectionGroups as $connections) {
            foreach ($connections as $connection) {
                if (! is_array($connection) || ! is_scalar($connection['username'] ?? null)) {
                    continue;
                }

                $username = $this->scraper->normalizeInstagramUsername((string) $connection['username']);

                if ($username === null) {
                    continue;
                }

                $sourcePublicUsername = $this->scraper->normalizeInstagramUsername(
                    (string) ($connection['sourcePublicUsername'] ?? ''),
                ) ?? '';
                $key = $sourcePublicUsername.'|'.$username;

                $mergedConnections[$key] = [
                    'username' => $username,
                    'displayName' => $this->nullableTrim($connection['displayName'] ?? null),
                    'profileUrl' => $this->nullableTrim($connection['profileUrl'] ?? null)
                        ?: 'https://www.instagram.com/'.$username.'/',
                    'profileImageUrl' => $this->nullableTrim($connection['profileImageUrl'] ?? $connection['profile_image_url'] ?? null),
                    'profileVisibility' => $this->nullableProfileVisibility(
                        $connection['profileVisibility'] ?? data_get($connection, 'hoverCard.profileVisibility'),
                    ),
                    'isPrivate' => is_bool($connection['isPrivate'] ?? null)
                        ? $connection['isPrivate']
                        : (is_bool(data_get($connection, 'hoverCard.isPrivate')) ? data_get($connection, 'hoverCard.isPrivate') : null),
                    'postsCount' => $this->nullableInteger($connection['postsCount'] ?? data_get($connection, 'hoverCard.postsCount')),
                    'followersCount' => $this->nullableInteger($connection['followersCount'] ?? data_get($connection, 'hoverCard.followersCount')),
                    'followingCount' => $this->nullableInteger($connection['followingCount'] ?? data_get($connection, 'hoverCard.followingCount')),
                    'hoverCard' => is_array($connection['hoverCard'] ?? null) ? $connection['hoverCard'] : null,
                    'sourcePublicUsername' => $sourcePublicUsername,
                    'sourceLists' => is_array($connection['sourceLists'] ?? null)
                        ? array_values(array_unique(array_map(
                            static fn ($sourceList): string => trim((string) $sourceList),
                            array_filter($connection['sourceLists'], 'is_scalar'),
                        )))
                        : [],
                ];
            }
        }

        return array_slice(array_values($mergedConnections), -80);
    }

    private function reportProgress(?callable $progress, array $payload): void
    {
        $this->assertActiveScanCurrent();

        if (! $progress) {
            return;
        }

        $progressPayload = $payload;
        $progressPayload['phase'] = $payload['phase'] ?? 'public-connections';
        $progressPayload['percent'] = max(0, min(100, (int) ($payload['percent'] ?? 0)));
        $progressPayload['message'] = (string) ($payload['message'] ?? 'Public-Profile-Verbindungsscan laeuft.');

        $progress($progressPayload);
    }

    private function withActiveScanControl(array $runtimeConfigOverrides = []): array
    {
        if ($this->activeScanControl === null) {
            return $runtimeConfigOverrides;
        }

        return [
            ...$runtimeConfigOverrides,
            '_scanControl' => $this->activeScanControl,
        ];
    }

    private function assertActiveScanCurrent(): void
    {
        if ($this->activeScanControl === null) {
            return;
        }

        $this->scanCoordinator->assertCurrent(
            (int) $this->activeScanControl['trackedPersonId'],
            (int) $this->activeScanControl['generation'],
        );
    }

    private function shouldStopGracefully(): bool
    {
        if ($this->activeScanControl === null) {
            return false;
        }

        return $this->scanCoordinator->shouldStopGracefully(
            (int) $this->activeScanControl['trackedPersonId'],
            (int) $this->activeScanControl['generation'],
        );
    }
}
