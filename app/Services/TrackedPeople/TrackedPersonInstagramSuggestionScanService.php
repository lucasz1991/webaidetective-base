<?php

namespace App\Services\TrackedPeople;

use App\Models\InstagramProfile;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramInferredConnection;
use App\Models\TrackedPersonInstagramSuggestionScan;
use App\Services\Billing\ScanCreditService;
use App\Services\Social\InstagramScraper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TrackedPersonInstagramSuggestionScanService
{
    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly TrackedPersonInstagramScanCoordinator $scanCoordinator,
        private readonly InstagramProfileRelationshipStore $profileRelationshipStore,
        private readonly ScanCreditService $scanCreditService,
        private readonly InstagramScanPolicyService $scanPolicies,
    ) {}

    private ?array $activeScanControl = null;

    public function scan(
        TrackedPerson $trackedPerson,
        ?callable $progress = null,
        ?string $targetUsernameOverride = null,
    ): TrackedPersonInstagramSuggestionScan {
        return $this->runScan($trackedPerson, $progress, $targetUsernameOverride, false);
    }

    public function scanDeepSearch(
        TrackedPerson $trackedPerson,
        ?callable $progress = null,
        ?string $targetUsernameOverride = null,
    ): TrackedPersonInstagramSuggestionScan {
        return $this->runScan($trackedPerson, $progress, $targetUsernameOverride, true);
    }

    public function scanProfile(
        InstagramProfile $profile,
        int $userId,
        ?callable $progress = null,
    ): TrackedPersonInstagramSuggestionScan {
        return $this->runProfileScan($profile, $userId, $progress, false);
    }

    public function scanProfileDeepSearch(
        InstagramProfile $profile,
        int $userId,
        ?callable $progress = null,
    ): TrackedPersonInstagramSuggestionScan {
        return $this->runProfileScan($profile, $userId, $progress, true);
    }

    private function runScan(
        TrackedPerson $trackedPerson,
        ?callable $progress,
        ?string $targetUsernameOverride,
        bool $deepSearch,
    ): TrackedPersonInstagramSuggestionScan {
        $targetUsername = $this->scraper->normalizeInstagramUsername(
            $targetUsernameOverride ?: $trackedPerson->instagram_username,
        );

        if ($targetUsername === null) {
            throw new \RuntimeException('Fuer diese Person ist kein Instagram-Name hinterlegt.');
        }

        $scanControl = $this->scanCoordinator->begin(
            $trackedPerson->id,
            $deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan',
        );

        Cache::lock('tracked-person-instagram-suggestion-scan:'.$trackedPerson->id, 3600)->forceRelease();
        $lock = Cache::lock('tracked-person-instagram-suggestion-scan:'.$trackedPerson->id, 3600);

        if (! $lock->get()) {
            $this->scanCoordinator->finish($trackedPerson->id, (int) $scanControl['generation']);
            throw new \RuntimeException('Fuer diese Person laeuft bereits ein Vorschlaege-Scan.');
        }

        $this->activeScanControl = $scanControl;

        try {
            return $this->scanWithLock(
                $trackedPerson,
                $this->profileRelationshipStore->syncTrackedPersonProfile($trackedPerson),
                (int) $trackedPerson->user_id,
                $targetUsername,
                $progress,
                $deepSearch,
            );
        } finally {
            $lock->release();
            $this->scanCoordinator->finish($trackedPerson->id, (int) $scanControl['generation']);
            $this->activeScanControl = null;
        }
    }

    private function runProfileScan(
        InstagramProfile $profile,
        int $userId,
        ?callable $progress,
        bool $deepSearch,
    ): TrackedPersonInstagramSuggestionScan {
        $targetUsername = $this->scraper->normalizeInstagramUsername($profile->username);

        if ($targetUsername === null || $userId <= 0) {
            throw new \RuntimeException('Das Instagram-Profil kann nicht gescannt werden.');
        }

        $scanContextId = -1 * (int) $profile->id;
        $scanControl = $this->scanCoordinator->begin(
            $scanContextId,
            $deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan',
        );
        $lockKey = 'instagram-profile-suggestion-scan:'.$profile->id;
        Cache::lock($lockKey, 3600)->forceRelease();
        $lock = Cache::lock($lockKey, 3600);

        if (! $lock->get()) {
            $this->scanCoordinator->finish($scanContextId, (int) $scanControl['generation']);
            throw new \RuntimeException('Fuer dieses Profil laeuft bereits ein Vorschlaege-Scan.');
        }

        $this->activeScanControl = $scanControl;

        try {
            return $this->scanWithLock(
                null,
                $profile,
                $userId,
                $targetUsername,
                $progress,
                $deepSearch,
            );
        } finally {
            $lock->release();
            $this->scanCoordinator->finish($scanContextId, (int) $scanControl['generation']);
            $this->activeScanControl = null;
        }
    }

    private function scanWithLock(
        ?TrackedPerson $trackedPerson,
        ?InstagramProfile $profile,
        int $userId,
        string $targetUsername,
        ?callable $progress = null,
        bool $deepSearch = false,
    ): TrackedPersonInstagramSuggestionScan {
        $profile ??= $this->profileRelationshipStore->ensureProfile($targetUsername);
        $deepSearchPolicy = $this->scanPolicies->for('suggestion_deep_search');
        $skipPreviouslyChecked = (bool) ($deepSearchPolicy['skip_previously_checked'] ?? true);
        $noMatchSkipAfter = max(1, min(100, (int) ($deepSearchPolicy['no_match_skip_after'] ?? 2)));

        $liveConnections = [];
        $liveObservedSuggestions = [];
        $liveSuggestionDebug = [
            'events' => [],
            'scrollEvents' => [],
            'finalUsernames' => [],
        ];

        $this->reportProgress($progress, [
            'phase' => 'suggestions',
            'percent' => 1,
            'message' => ($deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan').' wird vorbereitet.',
            'foundSuggestions' => 0,
            'suggestionConnections' => [],
            'observedSuggestionCount' => 0,
            'observedSuggestions' => [],
        ]);

        // Determine whether to elevate a normal scan to a full checked run every 3rd scan
        $recheckEvery = 3;
        $previousScanCount = 0;
        if ($trackedPerson) {
            $previousScanCount = TrackedPersonInstagramSuggestionScan::query()
                ->where('tracked_person_id', $trackedPerson->id)
                ->count();
        } elseif ($profile) {
            $previousScanCount = TrackedPersonInstagramSuggestionScan::query()
                ->where('instagram_profile_id', $profile->id)
                ->count();
        }
        $elevateToDeepSearch = (!$deepSearch) && ($previousScanCount % $recheckEvery === $recheckEvery - 1);

        // Resume support: detect last incomplete scan and build a pending list
        $lastScan = $this->lastSuggestionScanForContext($trackedPerson, $profile);
        $resumePendingOnly = false;
        $pendingCandidates = [];
        if ($lastScan) {
            [$resumePendingOnly, $pendingCandidates] = $this->buildResumePendingFromLastScan($lastScan);
        }

        try {
            $payload = $this->scraper->scrape(
                $targetUsername,
                // On every 3rd normal scan, run the checked scan under the hood
                $deepSearch || $elevateToDeepSearch ? 'suggestion-connections' : 'suggestions',
                function (array $state) use ($trackedPerson, $targetUsername, $progress, &$liveConnections, &$liveObservedSuggestions, &$liveSuggestionDebug): void {
                    if (array_key_exists('suggestionConnections', $state)) {
                        $liveConnections = $this->mergeSuggestionConnections(
                            $liveConnections,
                            $this->normalizeSuggestionConnections($state['suggestionConnections']),
                        );

                        if ($trackedPerson && $liveConnections !== []) {
                            $this->storeInferredSuggestionConnections(
                                $trackedPerson,
                                null,
                                $targetUsername,
                                $liveConnections,
                                ['progress' => true],
                                now('UTC'),
                            );
                        }
                    }

                    if (array_key_exists('observedSuggestions', $state)) {
                        $liveObservedSuggestions = $this->mergeObservedSuggestionItems(
                            $liveObservedSuggestions,
                            $this->normalizeObservedSuggestionItems($state['observedSuggestions']),
                        );
                        $liveSuggestionDebug['finalUsernames'] = collect($liveObservedSuggestions)
                            ->pluck('username')
                            ->filter()
                            ->take(120)
                            ->values()
                            ->all();
                    }

                    if (is_array($state['suggestionCollectionDebug'] ?? null)) {
                        $debugEvent = $state['suggestionCollectionDebug'];
                        if (is_scalar($state['liveScreenshotUrl'] ?? null)) {
                            $debugEvent['liveScreenshotUrl'] = (string) $state['liveScreenshotUrl'];
                        }
                        if (is_array($debugEvent['surfaceBeforeCollection'] ?? null)) {
                            $liveSuggestionDebug['surfaceBeforeCollection'] = $debugEvent['surfaceBeforeCollection'];
                        }
                        $targetKey = ($debugEvent['type'] ?? null) === 'scroll' ? 'scrollEvents' : 'events';
                        $liveSuggestionDebug[$targetKey][] = $debugEvent;
                        $liveSuggestionDebug[$targetKey] = array_slice($liveSuggestionDebug[$targetKey], -80);
                    }

                    $observedSuggestionCount = max(
                        count($liveObservedSuggestions),
                        (int) ($state['observedSuggestionCount'] ?? 0),
                    );

                    $this->reportProgress($progress, [
                        ...$state,
                        'phase' => 'suggestions',
                        'foundSuggestions' => count($liveConnections),
                        'suggestionConnections' => $liveConnections,
                        'observedSuggestionCount' => $observedSuggestionCount,
                        'observedSuggestions' => $liveObservedSuggestions,
                    ]);
                },
                $this->withActiveScanControl([
                    'suggestionDebug' => true,
                    // For explicit DeepSearch with history, keep existing behavior
                    ...($deepSearch && $trackedPerson && $skipPreviouslyChecked
                        ? ['suggestionCandidateHistory' => $this->buildSuggestionCandidateHistory($trackedPerson, $noMatchSkipAfter)]
                        : []),
                    // On elevated 3rd scan, force full recheck (no skipping)
                    ...($elevateToDeepSearch ? ['suggestionSkipPreviouslyChecked' => false] : []),
                    // Resume-only: if last scan incomplete, pass pending list and a hint to prefer resume
                    ...($pendingCandidates !== [] ? [
                        'suggestionPendingCandidates' => $pendingCandidates,
                        'suggestionResumePendingOnly' => $resumePendingOnly,
                    ] : []),
                ]),
            );
        } catch (\Throwable $exception) {
            $scanLabel = $deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan';
            $message = $scanLabel.' fehlgeschlagen: '.$exception->getMessage();
            $payload = [
                'ok' => false,
                'operationMode' => $deepSearch ? 'suggestion-connections' : 'suggestions',
                'statusLevel' => 'error',
                'statusMessage' => $message,
                'error' => $exception->getMessage(),
                'suggestionConnections' => $liveConnections,
                'suggestionScan' => [
                    'ok' => false,
                    'statusLevel' => 'error',
                    'statusMessage' => $message,
                    'targetUsername' => $targetUsername,
                    'attempted' => true,
                    'available' => count($liveObservedSuggestions) > 0,
                    'observedCount' => count($liveObservedSuggestions),
                    'checkedCount' => 0,
                    'matchCount' => count($liveConnections),
                    'rateLimited' => str_contains($exception->getMessage(), '429'),
                    'rateLimitText' => str_contains($exception->getMessage(), '429') ? $exception->getMessage() : null,
                    'observedSuggestions' => $liveObservedSuggestions,
                    'matches' => $liveConnections,
                    'targetCollectionDebug' => [
                        ...$liveSuggestionDebug,
                        'error' => $exception->getMessage(),
                    ],
                ],
            ];

            $this->reportProgress($progress, [
                'phase' => 'suggestions',
                'percent' => 100,
                'message' => $message,
                'foundSuggestions' => count($liveConnections),
                'suggestionConnections' => $liveConnections,
                'observedSuggestionCount' => count($liveObservedSuggestions),
                'observedSuggestions' => $liveObservedSuggestions,
            ]);
        }

        return $this->storeScan(
            $trackedPerson,
            $profile,
            $userId,
            $targetUsername,
            $payload,
            $liveConnections,
            $deepSearch,
        );
    }

    private function storeScan(
        ?TrackedPerson $trackedPerson,
        ?InstagramProfile $profile,
        int $userId,
        string $targetUsername,
        array $payload,
        array $liveConnections,
        bool $deepSearch,
    ): TrackedPersonInstagramSuggestionScan {
        $this->assertActiveScanCurrent();

        $payload = $this->normalizePayloadScreenshotPaths($payload);
        $scanPayload = $this->suggestionPayload($payload);
        $connections = $this->mergeSuggestionConnections(
            $liveConnections,
            $this->normalizeSuggestionConnections($payload['suggestionConnections'] ?? []),
            $this->normalizeSuggestionConnections($scanPayload['matches'] ?? []),
        );
        $analyzedAt = now('UTC');

        $scan = DB::transaction(function () use (
            $trackedPerson,
            $profile,
            $userId,
            $targetUsername,
            $payload,
            $scanPayload,
            $connections,
            $analyzedAt,
            $deepSearch,
        ): TrackedPersonInstagramSuggestionScan {
            $scan = TrackedPersonInstagramSuggestionScan::create([
                'tracked_person_id' => $trackedPerson?->id,
                'instagram_profile_id' => $profile?->id,
                'user_id' => $userId,
                'target_username' => $targetUsername,
                'status_level' => (string) ($payload['statusLevel'] ?? $scanPayload['statusLevel'] ?? 'unknown'),
                'status_message' => (string) ($payload['statusMessage'] ?? $scanPayload['statusMessage'] ?? (
                    $deepSearch ? 'Vorschlaege DeepSearch abgeschlossen.' : 'Vorschlaege-Scan abgeschlossen.'
                )),
                'suggestions_observed_count' => (int) ($scanPayload['observedCount'] ?? count($scanPayload['suggestions'] ?? [])),
                'suggestions_checked_count' => (int) ($scanPayload['checkedCount'] ?? 0),
                'suggestion_matches_count' => count($connections),
                'gracefully_stopped' => (bool) ($payload['gracefullyStopped'] ?? $scanPayload['gracefullyStopped'] ?? false),
                'raw_payload' => $payload,
                'analyzed_at' => $analyzedAt,
            ]);

            $this->storeObservedSuggestionProfiles($trackedPerson, $payload, $analyzedAt);
            if ($deepSearch) {
                if ($trackedPerson) {
                    $this->storeInferredSuggestionConnections(
                        $trackedPerson,
                        $scan,
                        $targetUsername,
                        $connections,
                        $payload,
                        $analyzedAt,
                    );
                }
                $this->storePublicListRelationships(
                    $trackedPerson,
                    $scan,
                    $targetUsername,
                    $connections,
                    $analyzedAt,
                );
            }

            return $scan;
        });

        $this->scanCreditService->charge(
            $userId,
            $scan,
            $payload,
            'Instagram-'.($deepSearch ? 'Vorschlaege DeepSearch' : 'Vorschlaege-Scan').' @'.$targetUsername,
        );

        return $scan;
    }

    private function storeInferredSuggestionConnections(
        TrackedPerson $trackedPerson,
        ?TrackedPersonInstagramSuggestionScan $scan,
        string $targetUsername,
        array $connections,
        array $payload,
        Carbon $seenAt,
    ): void {
        if ($connections === []) {
            return;
        }

        $this->profileRelationshipStore->syncTrackedPersonProfile($trackedPerson);

        foreach ($connections as $connection) {
            $candidateUsername = $this->scraper->normalizeInstagramUsername((string) ($connection['username'] ?? ''));

            if ($candidateUsername === null) {
                continue;
            }

            $sourceUsername = $this->scraper->normalizeInstagramUsername(
                (string) ($connection['sourceSuggestionUsername'] ?? $connection['sourcePublicUsername'] ?? $candidateUsername),
            ) ?? $candidateUsername;
            $candidateProfile = $this->profileRelationshipStore->ensureProfile($candidateUsername, [
                'display_name' => $this->nullableTrim($connection['displayName'] ?? null),
                'profile_url' => $this->nullableTrim($connection['profileUrl'] ?? null),
                'profile_image_url' => $this->nullableTrim($connection['profileImageUrl'] ?? $connection['profile_image_url'] ?? null),
                'is_private' => $this->normalizeSuggestionProfileIsPrivate($connection),
                'profile_visibility' => $this->normalizeSuggestionProfileVisibility($connection),
                'posts_count' => is_numeric($connection['postsCount'] ?? null) ? (int) $connection['postsCount'] : null,
                'followers_count' => is_numeric($connection['followersCount'] ?? null) ? (int) $connection['followersCount'] : null,
                'following_count' => is_numeric($connection['followingCount'] ?? null) ? (int) $connection['followingCount'] : null,
                'last_scanned_at' => $seenAt,
                'raw_profile' => [
                    'source' => 'suggestion_scan_match',
                    'tracked_person_id' => $trackedPerson->id,
                    'suggestion_scan_id' => $scan?->id,
                    'source_public_username' => $sourceUsername,
                ],
            ]);
            $existing = TrackedPersonInstagramInferredConnection::query()
                ->where('tracked_person_id', $trackedPerson->id)
                ->whereNull('public_profile_id')
                ->where('relationship_type', 'suggestion_connection')
                ->where('candidate_username', $candidateUsername)
                ->first();

            TrackedPersonInstagramInferredConnection::updateOrCreate(
                [
                    'tracked_person_id' => $trackedPerson->id,
                    'public_profile_id' => null,
                    'relationship_type' => 'suggestion_connection',
                    'candidate_username' => $candidateUsername,
                ],
                [
                    'scan_id' => null,
                    'user_id' => $trackedPerson->user_id,
                    'source_public_username' => $sourceUsername,
                    'candidate_display_name' => $this->nullableTrim($connection['displayName'] ?? null),
                    'candidate_profile_url' => $this->nullableTrim($connection['profileUrl'] ?? null)
                        ?: 'https://www.instagram.com/'.$candidateUsername.'/',
                    'source_lists' => $connection['sourceLists'] ?? ['profile_suggestions'],
                    'evidence' => [
                        ...$connection,
                        'targetUsername' => $targetUsername,
                        'suggestionScanId' => $scan?->id,
                        'rawScanStatus' => [
                            'statusLevel' => $payload['statusLevel'] ?? null,
                            'statusMessage' => $payload['statusMessage'] ?? null,
                        ],
                    ],
                    'status' => 'active',
                    'first_seen_at' => $existing?->first_seen_at ?: $seenAt,
                    'last_seen_at' => $seenAt,
                    ...$this->profileColumnData($candidateProfile?->id, $candidateProfile?->id),
                ],
            );
        }
    }

    private function storeObservedSuggestionProfiles(
        ?TrackedPerson $trackedPerson,
        array $payload,
        Carbon $seenAt,
    ): void {
        $scanPayload = $this->suggestionPayload($payload);
        $candidates = $this->mergeObservedSuggestionItems(
            $this->normalizeObservedSuggestionItems($scanPayload['observedSuggestions'] ?? []),
            $this->normalizeObservedSuggestionItems($scanPayload['checkedCandidates'] ?? []),
            $this->normalizeObservedSuggestionItems($scanPayload['matches'] ?? []),
            $this->normalizeObservedSuggestionItems($payload['suggestionConnections'] ?? []),
        );

        foreach ($candidates as $candidate) {
            $username = $this->scraper->normalizeInstagramUsername((string) ($candidate['username'] ?? ''));

            if ($username === null) {
                continue;
            }

            $this->profileRelationshipStore->ensureProfile($username, [
                'display_name' => $this->nullableTrim($candidate['displayName'] ?? null),
                'profile_url' => $this->nullableTrim($candidate['profileUrl'] ?? null),
                'profile_image_url' => $this->nullableTrim($candidate['profileImageUrl'] ?? $candidate['profile_image_url'] ?? null),
                'is_private' => $this->normalizeSuggestionProfileIsPrivate($candidate),
                'profile_visibility' => $this->normalizeSuggestionProfileVisibility($candidate),
                'posts_count' => is_numeric($candidate['postsCount'] ?? null) ? (int) $candidate['postsCount'] : null,
                'followers_count' => is_numeric($candidate['followersCount'] ?? null) ? (int) $candidate['followersCount'] : null,
                'following_count' => is_numeric($candidate['followingCount'] ?? null) ? (int) $candidate['followingCount'] : null,
                'last_scanned_at' => $seenAt,
                'raw_profile' => [
                    'source' => 'suggestion_scan_observed',
                    'tracked_person_id' => $trackedPerson?->id,
                    'matched' => (bool) ($candidate['matched'] ?? false),
                    'checked' => (bool) ($candidate['checked'] ?? false),
                    'skipped_reason' => $this->nullableTrim($candidate['skippedReason'] ?? null),
                ],
            ]);
        }
    }

    private function storePublicListRelationships(
        ?TrackedPerson $trackedPerson,
        ?TrackedPersonInstagramSuggestionScan $scan,
        string $targetUsername,
        array $connections,
        Carbon $seenAt,
    ): void {
        $targetProfile = $this->profileRelationshipStore->ensureProfile($targetUsername);

        if (! $targetProfile) {
            return;
        }

        foreach ($connections as $connection) {
            $candidateUsername = $this->scraper->normalizeInstagramUsername((string) ($connection['username'] ?? ''));

            if ($candidateUsername === null) {
                continue;
            }

            $candidateItem = [[
                'username' => $candidateUsername,
                'displayName' => $this->nullableTrim($connection['displayName'] ?? null),
                'profileUrl' => $this->nullableTrim($connection['profileUrl'] ?? null)
                    ?: 'https://www.instagram.com/'.$candidateUsername.'/',
                'profileImageUrl' => $this->nullableTrim($connection['profileImageUrl'] ?? $connection['profile_image_url'] ?? null),
                'profileVisibility' => $this->normalizeSuggestionProfileVisibility($connection),
                'isPrivate' => $this->normalizeSuggestionProfileIsPrivate($connection),
                'postsCount' => is_numeric($connection['postsCount'] ?? null) ? (int) $connection['postsCount'] : null,
                'followersCount' => is_numeric($connection['followersCount'] ?? null) ? (int) $connection['followersCount'] : null,
                'followingCount' => is_numeric($connection['followingCount'] ?? null) ? (int) $connection['followingCount'] : null,
            ]];
            $evidence = [
                'source' => 'suggestion_public_list_inverse',
                'suggestion_scan_id' => $scan?->id,
                'candidate_username' => $candidateUsername,
                'public_list_search' => is_array($connection['publicListSearch'] ?? null)
                    ? $connection['publicListSearch']
                    : [],
            ];

            if ((bool) ($connection['targetFoundInFollowing'] ?? false)) {
                $this->profileRelationshipStore->syncObservedRelationshipPreview(
                    $targetProfile,
                    $trackedPerson,
                    'followers',
                    $candidateItem,
                    $seenAt,
                    [
                        ...$evidence,
                        'inferred_from' => 'candidate_following_contains_target',
                    ],
                );
            }

            if ((bool) ($connection['targetFoundInFollowers'] ?? false)) {
                $this->profileRelationshipStore->syncObservedRelationshipPreview(
                    $targetProfile,
                    $trackedPerson,
                    'following',
                    $candidateItem,
                    $seenAt,
                    [
                        ...$evidence,
                        'inferred_from' => 'candidate_followers_contains_target',
                    ],
                );
            }
        }
    }

    private function suggestionPayload(array $payload): array
    {
        $suggestionPayload = $payload['suggestionScan'] ?? data_get($payload, 'profile.suggestionScan', []);

        return is_array($suggestionPayload) ? $suggestionPayload : [];
    }

    private function lastSuggestionScanForContext(?TrackedPerson $trackedPerson, ?InstagramProfile $profile): ?TrackedPersonInstagramSuggestionScan
    {
        if ($trackedPerson) {
            return $trackedPerson->instagramSuggestionScans()->latest('analyzed_at')->first();
        }

        if ($profile) {
            return TrackedPersonInstagramSuggestionScan::query()
                ->where('instagram_profile_id', $profile->id)
                ->latest('analyzed_at')
                ->first();
        }

        return null;
    }

    private function buildResumePendingFromLastScan(TrackedPersonInstagramSuggestionScan $lastScan): array
    {
        $payload = is_array($lastScan->raw_payload) ? $lastScan->raw_payload : [];
        $scanPayload = $this->suggestionPayload($payload);
        $observed = $this->normalizeObservedSuggestionItems($scanPayload['observedSuggestions'] ?? []);
        $checked = $this->normalizeObservedSuggestionItems($scanPayload['checkedCandidates'] ?? []);

        // Determine if last scan was incomplete
        $observedCount = (int) ($scanPayload['observedCount'] ?? count($observed));
        $checkedCount = (int) ($scanPayload['checkedCount'] ?? count(array_filter($checked, static fn ($c) => (bool) ($c['checked'] ?? false))));
        $incomplete = ($observedCount > 0) && ($checkedCount < $observedCount);

        // Collect pending usernames: observed but not checked, and retriable errors from checkedCandidates
        $retryableReasons = ['candidate-error', 'candidate-navigation-error', 'candidate-http-429'];
        $pendingByUsername = [];

        foreach ($observed as $item) {
            $username = $this->scraper->normalizeInstagramUsername($item['username'] ?? null);
            if ($username === null) {
                continue;
            }

            $checkedFlag = (bool) ($item['checked'] ?? false);
            $skipped = (bool) ($item['skipped'] ?? false);
            $skippedReason = is_scalar($item['skippedReason'] ?? null) ? (string) $item['skippedReason'] : null;

            // Exclude already-saved matches or permanently dismissed non-matches
            if (in_array($skippedReason, ['already-saved-match', 'already-dismissed-no-match'], true)) {
                continue;
            }

            if (! $checkedFlag) {
                $pendingByUsername[$username] = [
                    'username' => $username,
                    'profileUrl' => $this->nullableTrim($item['profileUrl'] ?? null) ?: 'https://www.instagram.com/'.$username.'/',
                ];
            }
        }

        foreach ($scanPayload['checkedCandidates'] ?? [] as $candidate) {
            if (! is_array($candidate) || ! is_scalar($candidate['username'] ?? null)) {
                continue;
            }
            $username = $this->scraper->normalizeInstagramUsername((string) $candidate['username']);
            if ($username === null) {
                continue;
            }

            $isChecked = (bool) ($candidate['checked'] ?? false);
            $skipped = (bool) ($candidate['skipped'] ?? false);
            $skippedReason = is_scalar($candidate['skippedReason'] ?? null) ? (string) $candidate['skippedReason'] : null;

            // Retry candidates that failed due to transient errors
            if (! $isChecked && $skipped && in_array($skippedReason, $retryableReasons, true)) {
                $pendingByUsername[$username] = [
                    'username' => $username,
                    'profileUrl' => $this->nullableTrim($candidate['profileUrl'] ?? null) ?: 'https://www.instagram.com/'.$username.'/',
                ];
            }
        }

        $pending = array_values($pendingByUsername);

        return [$incomplete, $pending];
    }

    private function buildSuggestionCandidateHistory(
        TrackedPerson $trackedPerson,
        int $noMatchSkipAfter,
    ): array {
        $history = [];

        TrackedPersonInstagramInferredConnection::query()
            ->where('tracked_person_id', $trackedPerson->id)
            ->where('relationship_type', 'suggestion_connection')
            ->where('status', 'active')
            ->get(['candidate_username'])
            ->each(function (TrackedPersonInstagramInferredConnection $connection) use (&$history): void {
                $username = $this->scraper->normalizeInstagramUsername($connection->candidate_username);

                if ($username === null) {
                    return;
                }

                $history[$username] = [
                    ...($history[$username] ?? []),
                    'hasMatch' => true,
                    'noMatchChecks' => (int) ($history[$username]['noMatchChecks'] ?? 0),
                    'permanentlyDismissed' => false,
                ];
            });

        $trackedPerson
            ->instagramSuggestionScans()
            ->latest('analyzed_at')
            ->limit(100)
            ->get()
            ->each(function (TrackedPersonInstagramSuggestionScan $scan) use (&$history, $noMatchSkipAfter): void {
                $payload = is_array($scan->raw_payload) ? $scan->raw_payload : [];
                $scanPayload = $this->suggestionPayload($payload);

                foreach ($this->normalizeSuggestionConnections($payload['suggestionConnections'] ?? []) as $connection) {
                    $username = $this->scraper->normalizeInstagramUsername($connection['username'] ?? null);

                    if ($username === null) {
                        continue;
                    }

                    $history[$username] = [
                        ...($history[$username] ?? []),
                        'hasMatch' => true,
                        'noMatchChecks' => (int) ($history[$username]['noMatchChecks'] ?? 0),
                        'permanentlyDismissed' => false,
                    ];
                }

                foreach ($this->normalizeSuggestionConnections($scanPayload['matches'] ?? []) as $connection) {
                    $username = $this->scraper->normalizeInstagramUsername($connection['username'] ?? null);

                    if ($username === null) {
                        continue;
                    }

                    $history[$username] = [
                        ...($history[$username] ?? []),
                        'hasMatch' => true,
                        'noMatchChecks' => (int) ($history[$username]['noMatchChecks'] ?? 0),
                        'permanentlyDismissed' => false,
                    ];
                }

                foreach ($scanPayload['checkedCandidates'] ?? [] as $candidate) {
                    if (! is_array($candidate)) {
                        continue;
                    }

                    $rawUsername = $candidate['username'] ?? null;

                    if (! is_scalar($rawUsername)) {
                        continue;
                    }

                    $username = $this->scraper->normalizeInstagramUsername((string) $rawUsername);

                    if ($username === null || (bool) ($history[$username]['hasMatch'] ?? false)) {
                        continue;
                    }

                    $hasCandidateMatch = (bool) ($candidate['targetFoundAsSuggestion'] ?? false)
                        || (bool) ($candidate['targetFoundInPublicLists'] ?? false)
                        || (bool) ($candidate['targetFoundInFollowers'] ?? false)
                        || (bool) ($candidate['targetFoundInFollowing'] ?? false);

                    if ($hasCandidateMatch) {
                        $history[$username] = [
                            ...($history[$username] ?? []),
                            'hasMatch' => true,
                            'noMatchChecks' => (int) ($history[$username]['noMatchChecks'] ?? 0),
                            'permanentlyDismissed' => false,
                        ];

                        continue;
                    }

                    $checkMode = is_scalar($candidate['checkMode'] ?? null)
                        ? (string) $candidate['checkMode']
                        : 'profile-suggestions';
                    $wasDefinitiveNoMatch = (bool) ($candidate['checked'] ?? false)
                        && ! filled($candidate['error'] ?? null)
                        && (
                            $checkMode === 'public-lists'
                                ? (bool) ($candidate['publicListSearchConclusive'] ?? false)
                                    && ! (bool) ($candidate['publicListRateLimited'] ?? false)
                                : (bool) ($candidate['suggestionsAvailable'] ?? false)
                                    && ! (bool) ($candidate['suggestionsRateLimited'] ?? false)
                        );

                    if (! $wasDefinitiveNoMatch) {
                        continue;
                    }

                    $noMatchChecks = (int) ($history[$username]['noMatchChecks'] ?? 0) + 1;
                    $history[$username] = [
                        ...($history[$username] ?? []),
                        'hasMatch' => false,
                        'noMatchChecks' => $noMatchChecks,
                        'permanentlyDismissed' => $noMatchChecks >= $noMatchSkipAfter
                            || (
                                $noMatchSkipAfter <= 2
                                && (bool) ($candidate['finalDismissedAfterSecondMiss'] ?? false)
                            ),
                    ];
                }
            });

        return $history;
    }

    private function normalizeSuggestionConnections(mixed $connections): array
    {
        if (! is_array($connections)) {
            return [];
        }

        $normalized = [];

        foreach ($connections as $connection) {
            if (! is_array($connection) || ! is_scalar($connection['username'] ?? null)) {
                continue;
            }

            $username = $this->scraper->normalizeInstagramUsername((string) $connection['username']);

            if ($username === null) {
                continue;
            }

            $sourceUsername = $this->scraper->normalizeInstagramUsername(
                (string) ($connection['sourceSuggestionUsername'] ?? $connection['sourcePublicUsername'] ?? $username),
            ) ?? $username;
            $sourceLists = $this->normalizeSourceLists($connection, (bool) ($connection['targetFoundAsSuggestion'] ?? true));

            $normalized[$username] = [
                'username' => $username,
                'displayName' => $this->nullableTrim($connection['displayName'] ?? null),
                'profileUrl' => $this->nullableTrim($connection['profileUrl'] ?? null)
                    ?: 'https://www.instagram.com/'.$username.'/',
                'profileImageUrl' => $this->nullableTrim($connection['profileImageUrl'] ?? $connection['profile_image_url'] ?? null),
                'profileVisibility' => in_array(($connection['profileVisibility'] ?? null), ['public', 'private', 'unknown'], true)
                    ? $connection['profileVisibility']
                    : null,
                'isPrivate' => is_bool($connection['isPrivate'] ?? null) ? $connection['isPrivate'] : null,
                'postsCount' => is_numeric($connection['postsCount'] ?? null) ? (int) $connection['postsCount'] : null,
                'followersCount' => is_numeric($connection['followersCount'] ?? null) ? (int) $connection['followersCount'] : null,
                'followingCount' => is_numeric($connection['followingCount'] ?? null) ? (int) $connection['followingCount'] : null,
                'hoverCard' => is_array($connection['hoverCard'] ?? null) ? $connection['hoverCard'] : null,
                'sourceSuggestionUsername' => $sourceUsername,
                'sourcePublicUsername' => $sourceUsername,
                'targetFoundAsSuggestion' => (bool) ($connection['targetFoundAsSuggestion'] ?? true),
                'targetFoundInPublicLists' => (bool) ($connection['targetFoundInPublicLists'] ?? false),
                'targetFoundInFollowers' => (bool) ($connection['targetFoundInFollowers'] ?? false),
                'targetFoundInFollowing' => (bool) ($connection['targetFoundInFollowing'] ?? false),
                'sourceLists' => $sourceLists,
                'publicListSearch' => is_array($connection['publicListSearch'] ?? null)
                    ? $connection['publicListSearch']
                    : [],
                'suggestionPreview' => is_array($connection['suggestionPreview'] ?? null)
                    ? array_values(array_slice($connection['suggestionPreview'], 0, 12))
                    : [],
            ];
        }

        return array_values($normalized);
    }

    private function normalizeSourceLists(array $connection, bool $targetFoundAsSuggestion): array
    {
        $sourceLists = [];
        $rawSourceLists = $connection['sourceLists'] ?? $connection['source_lists'] ?? [];

        if (is_array($rawSourceLists)) {
            foreach ($rawSourceLists as $sourceList) {
                if (! is_scalar($sourceList)) {
                    continue;
                }

                $sourceList = $this->nullableTrim($sourceList);

                if ($sourceList !== null) {
                    $sourceLists[] = $sourceList;
                }
            }
        }

        if ($targetFoundAsSuggestion) {
            $sourceLists[] = 'profile_suggestions';
        }

        if ((bool) ($connection['targetFoundInFollowers'] ?? false)) {
            $sourceLists[] = 'public_profile_followers';
        }

        if ((bool) ($connection['targetFoundInFollowing'] ?? false)) {
            $sourceLists[] = 'public_profile_following';
        }

        return array_values(array_unique($sourceLists ?: ['profile_suggestions']));
    }

    private function mergeSuggestionConnections(array ...$connectionGroups): array
    {
        $merged = [];

        foreach ($connectionGroups as $connections) {
            foreach ($connections as $connection) {
                $username = $this->scraper->normalizeInstagramUsername((string) ($connection['username'] ?? ''));

                if ($username === null) {
                    continue;
                }

                $merged[$username] = [
                    ...($merged[$username] ?? []),
                    ...$connection,
                    'username' => $username,
                ];
            }
        }

        return array_values($merged);
    }

    private function normalizeObservedSuggestionItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! is_scalar($item['username'] ?? null)) {
                continue;
            }

            $username = $this->scraper->normalizeInstagramUsername((string) $item['username']);

            if ($username === null) {
                continue;
            }

            $skippedReason = $this->nullableTrim($item['skippedReason'] ?? null);
            $alreadyKnown = (bool) ($item['alreadyKnown'] ?? false)
                || in_array($skippedReason, ['already-saved-match', 'already-scanned-suggestion'], true)
                || (bool) ($item['previousTargetFoundAsSuggestion'] ?? false);

            $normalized[$username] = [
                'username' => $username,
                'displayName' => $this->nullableTrim($item['displayName'] ?? null),
                'profileUrl' => $this->nullableTrim($item['profileUrl'] ?? null)
                    ?: 'https://www.instagram.com/'.$username.'/',
                'profileImageUrl' => $this->nullableTrim($item['profileImageUrl'] ?? $item['profile_image_url'] ?? null),
                'profileVisibility' => in_array(($item['profileVisibility'] ?? null), ['public', 'private', 'unknown'], true)
                    ? $item['profileVisibility']
                    : null,
                'isPrivate' => is_bool($item['isPrivate'] ?? null) ? $item['isPrivate'] : null,
                'postsCount' => is_numeric($item['postsCount'] ?? null) ? (int) $item['postsCount'] : null,
                'followersCount' => is_numeric($item['followersCount'] ?? null) ? (int) $item['followersCount'] : null,
                'followingCount' => is_numeric($item['followingCount'] ?? null) ? (int) $item['followingCount'] : null,
                'checked' => array_key_exists('checked', $item) ? (bool) $item['checked'] : false,
                'skipped' => array_key_exists('skipped', $item) ? (bool) $item['skipped'] : false,
                'matched' => array_key_exists('matched', $item) ? (bool) $item['matched'] : false,
                'alreadyKnown' => $alreadyKnown,
                'dismissedFromSuggestions' => (bool) ($item['dismissedFromSuggestions'] ?? false),
                'skippedReason' => $skippedReason,
                'previousNoMatchChecks' => is_numeric($item['previousNoMatchChecks'] ?? null)
                    ? max(0, (int) $item['previousNoMatchChecks'])
                    : null,
            ];
        }

        return array_values($normalized);
    }

    private function mergeObservedSuggestionItems(array ...$itemGroups): array
    {
        $merged = [];

        foreach ($itemGroups as $items) {
            foreach ($items as $item) {
                $username = $this->scraper->normalizeInstagramUsername((string) ($item['username'] ?? ''));

                if ($username === null) {
                    continue;
                }

                $merged[$username] = [
                    ...($merged[$username] ?? []),
                    ...$item,
                    'username' => $username,
                    'alreadyKnown' => (bool) (($merged[$username]['alreadyKnown'] ?? false) || ($item['alreadyKnown'] ?? false)),
                    'checked' => (bool) (($merged[$username]['checked'] ?? false) || ($item['checked'] ?? false)),
                    'skipped' => (bool) (($merged[$username]['skipped'] ?? false) || ($item['skipped'] ?? false)),
                    'matched' => (bool) (($merged[$username]['matched'] ?? false) || ($item['matched'] ?? false)),
                ];
            }
        }

        return array_values($merged);
    }

    private function normalizePayloadScreenshotPaths(array $payload): array
    {
        foreach (['screenshotPath'] as $key) {
            if (! is_scalar($payload[$key] ?? null)) {
                continue;
            }

            $resolved = $this->scraper->resolvePublicStoragePath((string) $payload[$key]);

            if ($resolved !== null) {
                $payload[$key] = $resolved;
            }
        }

        return $payload;
    }

    private function profileColumnData(?int $sourceProfileId, ?int $candidateProfileId): array
    {
        if (
            ! Schema::hasColumn('tracked_person_instagram_inferred_connections', 'source_public_instagram_profile_id')
            || ! Schema::hasColumn('tracked_person_instagram_inferred_connections', 'candidate_instagram_profile_id')
        ) {
            return [];
        }

        return array_filter([
            'source_public_instagram_profile_id' => $sourceProfileId,
            'candidate_instagram_profile_id' => $candidateProfileId,
        ], static fn ($value): bool => $value !== null);
    }

    private function reportProgress(?callable $progress, array $payload): void
    {
        $this->assertActiveScanCurrent();

        if (! $progress) {
            return;
        }

        $payload['phase'] = $payload['phase'] ?? 'suggestions';
        $payload['percent'] = max(0, min(100, (int) ($payload['percent'] ?? 0)));
        $payload['message'] = (string) ($payload['message'] ?? 'Vorschlaege-Scan laeuft.');

        $progress($payload);
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

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeSuggestionProfileVisibility(array $item): ?string
    {
        $visibility = $this->nullableTrim($item['profileVisibility'] ?? $item['profile_visibility'] ?? null);

        if (in_array($visibility, ['public', 'private', 'unknown'], true)) {
            return $visibility;
        }

        $isPrivate = $this->normalizeSuggestionProfileIsPrivate($item);

        return $isPrivate === null ? null : ($isPrivate ? 'private' : 'public');
    }

    private function normalizeSuggestionProfileIsPrivate(array $item): ?bool
    {
        if (is_bool($item['isPrivate'] ?? null)) {
            return $item['isPrivate'];
        }

        if (is_bool($item['is_private'] ?? null)) {
            return $item['is_private'];
        }

        $visibility = $this->nullableTrim($item['profileVisibility'] ?? $item['profile_visibility'] ?? null);

        return match ($visibility) {
            'private' => true,
            'public' => false,
            default => null,
        };
    }
}
