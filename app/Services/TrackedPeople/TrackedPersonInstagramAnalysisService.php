<?php

namespace App\Services\TrackedPeople;

use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramSnapshot;
use App\Services\Billing\ScanCreditService;
use App\Services\Social\InstagramProfileDataExtractor;
use App\Services\Social\InstagramScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TrackedPersonInstagramAnalysisService
{
    private const MAX_VISIBLE_RETRY_ATTEMPTS = 3;

    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly InstagramProfileDataExtractor $extractor,
        private readonly TrackedPersonInstagramScanCoordinator $scanCoordinator,
        private readonly InstagramProfileRelationshipStore $profileRelationshipStore,
        private readonly ScanCreditService $scanCreditService,
    ) {
    }

    private ?array $activeScanControl = null;

    public function analyze(
        TrackedPerson $trackedPerson,
        ?callable $progress = null,
        bool $fullScan = false,
    ): TrackedPersonInstagramSnapshot
    {
        if (! $trackedPerson->instagram_username) {
            throw new \RuntimeException('Fuer diese Person ist kein Instagram-Name hinterlegt.');
        }

        $scanControl = $this->scanCoordinator->begin(
            $trackedPerson->id,
            $fullScan ? 'Instagram-Vollanalyse' : 'Instagram-Mini-Scan',
        );

        Cache::lock($this->analysisLockKey($trackedPerson), 3600)->forceRelease();
        $lock = Cache::lock($this->analysisLockKey($trackedPerson), 3600);

        if (! $lock->get()) {
            $this->scanCoordinator->finish($trackedPerson->id, (int) $scanControl['generation']);
            throw new \RuntimeException('Fuer diese Person laeuft bereits eine Instagram-Analyse. Bitte den laufenden Scan abwarten.');
        }

        $this->activeScanControl = $scanControl;

        try {
            return $this->analyzeWithLock($trackedPerson, $progress, $fullScan);
        } finally {
            $lock->release();
            $this->scanCoordinator->finish($trackedPerson->id, (int) $scanControl['generation']);
            $this->activeScanControl = null;
        }
    }

    public function scanRelationshipList(
        TrackedPerson $trackedPerson,
        string $relationship,
        ?callable $progress = null,
    ): TrackedPersonInstagramSnapshot {
        $relationship = Str::lower(trim($relationship));

        if (! in_array($relationship, ['followers', 'following'], true)) {
            throw new \InvalidArgumentException('Unbekannte Instagram-Liste.');
        }

        if (! $trackedPerson->instagram_username) {
            throw new \RuntimeException('Fuer diese Person ist kein Instagram-Name hinterlegt.');
        }

        $scanControl = $this->scanCoordinator->begin(
            $trackedPerson->id,
            $relationship === 'followers' ? 'Instagram-Followerliste' : 'Instagram-Gefolgt-Liste',
        );

        Cache::lock($this->analysisLockKey($trackedPerson), 3600)->forceRelease();
        $lock = Cache::lock($this->analysisLockKey($trackedPerson), 3600);

        if (! $lock->get()) {
            $this->scanCoordinator->finish($trackedPerson->id, (int) $scanControl['generation']);
            throw new \RuntimeException('Fuer diese Person laeuft bereits eine Instagram-Analyse. Bitte den laufenden Scan abwarten.');
        }

        $this->activeScanControl = $scanControl;

        try {
            return $this->scanRelationshipListWithLock($trackedPerson, $relationship, $progress);
        } finally {
            $lock->release();
            $this->scanCoordinator->finish($trackedPerson->id, (int) $scanControl['generation']);
            $this->activeScanControl = null;
        }
    }

    private function analyzeWithLock(
        TrackedPerson $trackedPerson,
        ?callable $progress = null,
        bool $fullScan = false,
    ): TrackedPersonInstagramSnapshot {
        $this->reportProgress($progress, [
            'phase' => 'start',
            'percent' => 1,
            'message' => $fullScan
                ? 'Vollstaendige Instagram-Analyse wird vorbereitet.'
                : 'Instagram-Mini-Scan wird vorbereitet.',
        ]);

        $previousSnapshot = $this->previousComparableSnapshot($trackedPerson);
        [$payload, $extracted, $attemptInfo] = $fullScan
            ? $this->scrapePortioned($trackedPerson->instagram_username, $progress, $previousSnapshot)
            : $this->scrapeMini($trackedPerson->instagram_username, $progress);
        $analyzedAt = now('UTC')->format('Y-m-d H:i:s');
        $persistedWarnings = [];
        $miniScanWithoutCounts = ($attemptInfo['scan_mode'] ?? null) === 'mini'
            && ! (bool) ($attemptInfo['counts_found'] ?? false);
        $preservedProfileFields = [];
        $extracted = $this->preserveMissingProfileValues(
            $extracted,
            $previousSnapshot,
            $preservedProfileFields,
        );
        $extracted = $this->preserveUnreliableProfileVisibility(
            $extracted,
            $previousSnapshot,
            $payload,
            $attemptInfo,
            $preservedProfileFields,
        );

        if ($preservedProfileFields !== []) {
            $attemptInfo['preserved_profile_fields'] = $preservedProfileFields;
            $persistedWarnings[] = 'Fehlende Profilwerte wurden aus dem letzten gueltigen Instagram-Snapshot fortgefuehrt, damit ein fehlerhafter Scan den Vergleichsstand nicht leert.';
        }

        $this->reportProgress($progress, [
            'phase' => 'saving',
            'percent' => 97,
            'message' => 'Analyseergebnis wird gespeichert.',
        ]);
        $this->assertActiveScanCurrent();

        $snapshot = DB::transaction(function () use (
            $trackedPerson,
            $previousSnapshot,
            $payload,
            $extracted,
            $attemptInfo,
            $analyzedAt,
            $miniScanWithoutCounts,
            &$persistedWarnings,
        ) {
            $snapshot = $trackedPerson->instagramSnapshots()->create([
                'instagram_username' => $trackedPerson->instagram_username,
                'full_name' => $extracted['full_name'],
                'biography' => $extracted['biography'],
                'posts_count' => $extracted['posts_count'],
                'followers_count' => $extracted['followers_count'],
                'following_count' => $extracted['following_count'],
                'profile_image_url' => $extracted['profile_image_url'],
                'profile_image_path' => null,
                'profile_image_hash' => null,
                'screenshot_path' => $this->scraper->resolvePublicStoragePath($payload['screenshotPath'] ?? null),
                'html_path' => $this->scraper->resolvePublicStoragePath($payload['htmlPath'] ?? null),
                'status_level' => $payload['statusLevel'] ?? 'error',
                'status_message' => $this->resolveStatusMessage($payload, $attemptInfo),
                'has_changes' => false,
                'detected_changes' => [],
                'raw_payload' => null,
                'analyzed_at' => $analyzedAt,
            ]);

            $extracted = $this->storeRelationshipListArtifacts(
                $trackedPerson,
                $snapshot,
                $extracted,
                $persistedWarnings,
                $previousSnapshot,
            );

            $isMiniScan = ($attemptInfo['scan_mode'] ?? null) === 'mini';
            $mediaUrls = $isMiniScan
                ? array_values(array_filter([$extracted['profile_image_url'] ?? null]))
                : ($extracted['image_urls'] ?? []);
            $storedMedia = $this->storeSnapshotMedia(
                $trackedPerson,
                $snapshot,
                $mediaUrls,
                $persistedWarnings,
                $previousSnapshot,
            );
            $profileMedia = collect($storedMedia)->firstWhere('is_profile_image', true);
            $profileImagePath = ($profileMedia['reused_existing'] ?? false)
                ? null
                : ($profileMedia['storage_path'] ?? null);
            $profileImageHash = $profileMedia['content_hash'] ?? null;

            if ($profileImageHash === null && $previousSnapshot?->profile_image_hash) {
                $profileImageHash = $previousSnapshot->profile_image_hash;
                $profileImagePath = $previousSnapshot->profile_image_path;
            }

            $detectedChanges = $miniScanWithoutCounts
                ? []
                : $this->detectSnapshotChanges($previousSnapshot, $extracted, $profileImageHash, $isMiniScan);
            $snapshotPayload = $this->buildStoredPayload(
                $payload,
                $extracted,
                $detectedChanges,
                $attemptInfo,
                $profileImageHash,
            );

            $snapshot->forceFill([
                'profile_image_path' => $profileImagePath,
                'profile_image_hash' => $profileImageHash,
                'has_changes' => $detectedChanges !== [],
                'detected_changes' => $detectedChanges,
                'raw_payload' => $this->appendPersistedWarnings($snapshotPayload, $persistedWarnings),
            ])->save();

            $trackedPersonUpdate = [
                'last_instagram_status_level' => $snapshot->status_level,
                'last_instagram_status_message' => $snapshot->status_message,
                'last_instagram_analyzed_at' => $snapshot->getRawOriginal('analyzed_at') ?: $analyzedAt,
            ];

            if ($profileImagePath) {
                $trackedPersonUpdate['profile_image_path'] = $profileImagePath;
                $trackedPersonUpdate['instagram_profile_image_path'] = $profileImagePath;
            }

            if ($profileImageHash) {
                $trackedPersonUpdate['profile_image_hash'] = $profileImageHash;
                $trackedPersonUpdate['instagram_profile_image_hash'] = $profileImageHash;
            }

            foreach ([
                'instagram_followers_count' => $extracted['followers_count'],
                'instagram_following_count' => $extracted['following_count'],
                'instagram_posts_count' => $extracted['posts_count'],
            ] as $field => $value) {
                if ($value !== null) {
                    $trackedPersonUpdate[$field] = $value;
                }
            }

            $trackedPerson->forceFill($trackedPersonUpdate)->save();

            $this->profileRelationshipStore->syncProfileFromSnapshot(
                $trackedPerson,
                $snapshot,
                $extracted,
                $payload,
                $attemptInfo,
            );

            return $snapshot;
        });

        $this->reportProgress($progress, [
            'phase' => 'done',
            'percent' => 100,
            'message' => $snapshot->status_level === 'error'
                ? ($fullScan ? 'Instagram-Analyse fehlgeschlagen.' : 'Instagram-Mini-Scan fehlgeschlagen.')
                : ($fullScan ? 'Instagram-Analyse abgeschlossen.' : 'Instagram-Mini-Scan abgeschlossen.'),
        ]);

        $this->scanCreditService->charge(
            (int) $trackedPerson->user_id,
            $snapshot,
            $payload,
            ($fullScan ? 'Instagram-Vollanalyse' : 'Instagram-Mini-Scan').' @'.$trackedPerson->instagram_username,
        );

        return $snapshot->fresh('media');
    }

    private function scanRelationshipListWithLock(
        TrackedPerson $trackedPerson,
        string $relationship,
        ?callable $progress = null,
    ): TrackedPersonInstagramSnapshot {
        $isFollowers = $relationship === 'followers';
        $label = $isFollowers ? 'Followerliste' : 'Gefolgt-Liste';
        $payloadKey = $isFollowers ? 'followersList' : 'followingList';
        $otherExtractedKey = $isFollowers ? 'following_list' : 'followers_list';
        $otherType = $isFollowers ? 'following' : 'followers';
        $expectedOverride = $isFollowers ? 'expectedFollowerCount' : 'expectedFollowingCount';
        $expectedCount = $isFollowers
            ? ($trackedPerson->instagram_followers_count ?? 0)
            : ($trackedPerson->instagram_following_count ?? 0);

        $this->reportProgress($progress, [
            'phase' => 'start',
            'percent' => 1,
            'message' => $label.' wird vorbereitet.',
        ]);

        $previousSnapshot = $this->previousComparableSnapshot($trackedPerson);

        $payload = $this->scraper->scrape(
            $trackedPerson->instagram_username,
            $relationship,
            $progress,
            $this->withActiveScanControl([
                $expectedOverride => max(0, (int) $expectedCount),
                'relationshipPrioritizedSearchUsernames' => [
                    $relationship => $this->previousRelationshipUsernamesForSearch($previousSnapshot, $relationship),
                ],
            ]),
            4,
            95,
        );
        $extracted = $this->extractor->extract($payload);
        $analyzedAt = now('UTC')->format('Y-m-d H:i:s');
        $persistedWarnings = [];

        $extracted[$otherExtractedKey] = $this->preservedRelationshipListForExtraction($previousSnapshot, $otherType);

        $phaseList = data_get($payload, 'profile.'.$payloadKey);
        $phaseResult = [
            'phase' => $relationship,
            'statusLevel' => $payload['statusLevel'] ?? 'unknown',
            'statusMessage' => $payload['statusMessage'] ?? null,
            'screenshotPath' => $this->scraper->resolvePublicStoragePath($payload['screenshotPath'] ?? null),
            'ok' => (bool) ($payload['ok'] ?? false),
            'count' => is_array($phaseList) ? (int) ($phaseList['count'] ?? 0) : 0,
            'available' => is_array($phaseList) ? (bool) ($phaseList['available'] ?? false) : false,
            'rateLimited' => is_array($phaseList) ? (bool) ($phaseList['rateLimited'] ?? false) : false,
            'listTemporarilyUnavailable' => is_array($phaseList) ? (bool) ($phaseList['listTemporarilyUnavailable'] ?? false) : false,
            'gracefullyStopped' => (bool) ($payload['gracefullyStopped'] ?? data_get($phaseList, 'gracefullyStopped', false)),
        ];
        $attemptInfo = [
            'scan_mode' => $relationship,
            'used_attempt' => 1,
            'max_attempts' => 1,
            'visible_counts_complete' => (bool) ($extracted['visible_counts_complete'] ?? false),
            'relationship_only' => true,
            'relationship' => $relationship,
            'phases' => [$phaseResult],
        ];
        $preservedProfileFields = [];
        $extracted = $this->preserveMissingProfileValues(
            $extracted,
            $previousSnapshot,
            $preservedProfileFields,
        );
        $extracted = $this->preserveUnreliableProfileVisibility(
            $extracted,
            $previousSnapshot,
            $payload,
            $attemptInfo,
            $preservedProfileFields,
        );

        if ($preservedProfileFields !== []) {
            $attemptInfo['preserved_profile_fields'] = $preservedProfileFields;
            $persistedWarnings[] = 'Fehlende Profilwerte wurden aus dem letzten gueltigen Instagram-Snapshot fortgefuehrt, damit ein fehlerhafter Listen-Scan den Vergleichsstand nicht leert.';
        }

        $this->reportProgress($progress, [
            'phase' => 'saving',
            'percent' => 97,
            'message' => $label.' wird gespeichert.',
        ]);
        $this->assertActiveScanCurrent();

        $snapshot = DB::transaction(function () use (
            $trackedPerson,
            $previousSnapshot,
            $payload,
            $extracted,
            $attemptInfo,
            $analyzedAt,
            &$persistedWarnings,
            $label,
        ) {
            $snapshot = $trackedPerson->instagramSnapshots()->create([
                'instagram_username' => $trackedPerson->instagram_username,
                'full_name' => $extracted['full_name'],
                'biography' => $extracted['biography'],
                'posts_count' => $extracted['posts_count'],
                'followers_count' => $extracted['followers_count'],
                'following_count' => $extracted['following_count'],
                'profile_image_url' => $extracted['profile_image_url'],
                'profile_image_path' => null,
                'profile_image_hash' => null,
                'screenshot_path' => $this->scraper->resolvePublicStoragePath($payload['screenshotPath'] ?? null),
                'html_path' => $this->scraper->resolvePublicStoragePath($payload['htmlPath'] ?? null),
                'status_level' => $payload['statusLevel'] ?? 'error',
                'status_message' => $this->resolveStatusMessage($payload, $attemptInfo),
                'has_changes' => false,
                'detected_changes' => [],
                'raw_payload' => null,
                'analyzed_at' => $analyzedAt,
            ]);

            $extracted = $this->storeRelationshipListArtifacts(
                $trackedPerson,
                $snapshot,
                $extracted,
                $persistedWarnings,
                $previousSnapshot,
            );

            $storedMedia = $this->storeSnapshotMedia(
                $trackedPerson,
                $snapshot,
                $extracted['image_urls'] ?? [],
                $persistedWarnings,
                $previousSnapshot,
            );
            $profileMedia = collect($storedMedia)->firstWhere('is_profile_image', true);
            $profileImagePath = ($profileMedia['reused_existing'] ?? false)
                ? null
                : ($profileMedia['storage_path'] ?? null);
            $profileImageHash = $profileMedia['content_hash'] ?? null;

            if ($profileImageHash === null && $previousSnapshot?->profile_image_hash) {
                $profileImageHash = $previousSnapshot->profile_image_hash;
                $profileImagePath = $previousSnapshot->profile_image_path;
            }

            $detectedChanges = $this->detectSnapshotChanges($previousSnapshot, $extracted, $profileImageHash);
            $snapshotPayload = $this->buildStoredPayload(
                $payload,
                $extracted,
                $detectedChanges,
                $attemptInfo,
                $profileImageHash,
            );

            $snapshot->forceFill([
                'profile_image_path' => $profileImagePath,
                'profile_image_hash' => $profileImageHash,
                'has_changes' => $detectedChanges !== [],
                'detected_changes' => $detectedChanges,
                'raw_payload' => $this->appendPersistedWarnings($snapshotPayload, $persistedWarnings),
            ])->save();

            $trackedPersonUpdate = [
                'last_instagram_status_level' => $snapshot->status_level,
                'last_instagram_status_message' => $label.'-Scan: '.$snapshot->status_message,
                'last_instagram_analyzed_at' => $snapshot->getRawOriginal('analyzed_at') ?: $analyzedAt,
            ];

            if ($profileImagePath) {
                $trackedPersonUpdate['profile_image_path'] = $profileImagePath;
                $trackedPersonUpdate['instagram_profile_image_path'] = $profileImagePath;
            }

            if ($profileImageHash) {
                $trackedPersonUpdate['profile_image_hash'] = $profileImageHash;
                $trackedPersonUpdate['instagram_profile_image_hash'] = $profileImageHash;
            }

            foreach ([
                'instagram_followers_count' => $extracted['followers_count'],
                'instagram_following_count' => $extracted['following_count'],
                'instagram_posts_count' => $extracted['posts_count'],
            ] as $field => $value) {
                if ($value !== null) {
                    $trackedPersonUpdate[$field] = $value;
                }
            }

            $trackedPerson->forceFill($trackedPersonUpdate)->save();

            $this->profileRelationshipStore->syncProfileFromSnapshot(
                $trackedPerson,
                $snapshot,
                $extracted,
                $payload,
                $attemptInfo,
            );

            return $snapshot;
        });

        $this->reportProgress($progress, [
            'phase' => 'done',
            'percent' => 100,
            'message' => $snapshot->status_level === 'error'
                ? $label.'-Scan fehlgeschlagen.'
                : $label.'-Scan abgeschlossen.',
        ]);

        $this->scanCreditService->charge(
            (int) $trackedPerson->user_id,
            $snapshot,
            $payload,
            'Instagram-'.$label.' @'.$trackedPerson->instagram_username,
        );

        return $snapshot->fresh('media');
    }

    private function analysisLockKey(TrackedPerson $trackedPerson): string
    {
        return 'tracked-person-instagram-analysis:'.$trackedPerson->getKey();
    }

    private function scrapeMini(string $username, ?callable $progress = null): array
    {
        $this->reportProgress($progress, [
            'phase' => 'profile',
            'percent' => 4,
            'message' => 'Oeffentliche Instagram-Grunddaten werden ohne Anmeldung geladen.',
        ]);

        $attempts = [];
        $phaseResults = [];
        [$payload, $extracted, $countsFound] = $this->runMiniScanAttempt(
            $username,
            $progress,
            [
                'autoLoginEnabled' => false,
                'accountPool' => [],
                'miniScanUseSession' => false,
            ],
            4,
            44,
        );
        $attempts[] = $this->summarizeMiniScanAttempt(1, 'mini-public', $payload, $extracted);
        $phaseResults[] = [
            'phase' => 'profile',
            'mode' => 'mini-public',
            'statusLevel' => $payload['statusLevel'] ?? 'unknown',
            'statusMessage' => $payload['statusMessage'] ?? null,
            'screenshotPath' => $this->scraper->resolvePublicStoragePath($payload['screenshotPath'] ?? null),
            'ok' => (bool) ($payload['ok'] ?? false),
        ];

        if (! $countsFound || ! (bool) ($payload['ok'] ?? false)) {
            if ($this->shouldStopGracefully() || (bool) ($payload['gracefullyStopped'] ?? false)) {
                $payload['ok'] = false;
                $payload['statusLevel'] = 'partial';
                $payload['statusMessage'] = 'Instagram-Mini-Scan wurde beendet; die bis dahin ermittelten Daten werden gespeichert.';

                return [
                    $payload,
                    $extracted,
                    [
                        'scan_mode' => 'mini',
                        'used_attempt' => count($attempts),
                        'max_attempts' => 2,
                        'session_fallback_used' => false,
                        'visible_counts_complete' => (bool) ($extracted['visible_counts_complete'] ?? false),
                        'counts_found' => $countsFound,
                        'gracefully_stopped' => true,
                        'attempts' => $attempts,
                        'phases' => $phaseResults,
                    ],
                ];
            }

            $this->reportProgress($progress, [
                'phase' => 'profile',
                'percent' => 45,
                'message' => 'Oeffentlicher Mini-Scan war nicht erfolgreich; Mini-Scan wird mit gespeicherter Instagram-Session wiederholt.',
            ]);

            [$sessionPayload, $sessionExtracted, $sessionCountsFound] = $this->runMiniScanAttempt(
                $username,
                $progress,
                [
                    'miniScanUseSession' => true,
                ],
                45,
                92,
            );

            $attempts[] = $this->summarizeMiniScanAttempt(2, 'mini-session', $sessionPayload, $sessionExtracted);
            $phaseResults[] = [
                'phase' => 'profile',
                'mode' => 'mini-session',
                'statusLevel' => $sessionPayload['statusLevel'] ?? 'unknown',
                'statusMessage' => $sessionPayload['statusMessage'] ?? null,
                'screenshotPath' => $this->scraper->resolvePublicStoragePath($sessionPayload['screenshotPath'] ?? null),
                'ok' => (bool) ($sessionPayload['ok'] ?? false),
            ];

            $payload = $sessionPayload;
            $extracted = $sessionExtracted;
            $countsFound = $sessionCountsFound;
        }

        if (! $countsFound) {
            $payload['ok'] = false;
            $payload['statusLevel'] = 'error';
            $payload['statusMessage'] = 'Instagram-Mini-Scan fehlgeschlagen: Es wurden auch mit Session keine Kennzahlen im sichtbaren DOM oder HTML gefunden.';
        }

        $this->reportProgress($progress, [
            'phase' => 'profile',
            'percent' => 93,
            'message' => 'Mini-Scan-Grunddaten wurden ausgelesen.',
        ]);

        return [
            $payload,
            $extracted,
            [
                'scan_mode' => 'mini',
                'used_attempt' => count($attempts),
                'max_attempts' => 2,
                'session_fallback_used' => count($attempts) > 1,
                'visible_counts_complete' => (bool) ($extracted['visible_counts_complete'] ?? false),
                'counts_found' => $countsFound,
                'attempts' => $attempts,
                'phases' => $phaseResults,
            ],
        ];
    }

    private function runMiniScanAttempt(
        string $username,
        ?callable $progress,
        array $runtimeConfigOverrides,
        int $progressStart,
        int $progressEnd,
    ): array {
        $payload = $this->scraper->scrape(
            $username,
            'mini',
            $progress,
            $this->withActiveScanControl($runtimeConfigOverrides),
            $progressStart,
            $progressEnd,
        );
        $extracted = $this->extractor->extract($payload);
        $countsFound = count(array_filter($extracted['count_sources'] ?? [])) > 0;

        return [$payload, $extracted, $countsFound];
    }

    private function summarizeMiniScanAttempt(int $attempt, string $mode, array $payload, array $extracted): array
    {
        return [
            'attempt' => $attempt,
            'mode' => $mode,
            'statusLevel' => $payload['statusLevel'] ?? 'unknown',
            'statusMessage' => $payload['statusMessage'] ?? null,
            'followersSource' => $extracted['count_sources']['followers'] ?? null,
            'followingSource' => $extracted['count_sources']['following'] ?? null,
            'postsSource' => $extracted['count_sources']['posts'] ?? null,
            'visibleCountsComplete' => (bool) ($extracted['visible_counts_complete'] ?? false),
            'profileVisibility' => $extracted['profile_visibility'] ?? 'unknown',
            'isPrivate' => $extracted['is_private'] ?? null,
            'sessionUsed' => $mode === 'mini-session',
        ];
    }

    private function scrapeUntilVisibleCounts(string $username, ?callable $progress = null): array
    {
        $attempts = [];
        $lastPayload = [];
        $lastExtracted = [];

        for ($attempt = 1; $attempt <= self::MAX_VISIBLE_RETRY_ATTEMPTS; $attempt++) {
            $this->reportProgress($progress, [
                'phase' => 'profile',
                'percent' => 4,
                'message' => 'Grunddaten werden geladen'.($attempt > 1 ? ' (Versuch '.$attempt.')' : '').'.',
            ]);

            $payload = $this->scraper->scrape(
                $username,
                'profile',
                $progress,
                $this->withActiveScanControl(),
                4,
                24,
            );
            $extracted = $this->extractor->extract($payload);

            $attempts[] = [
                'attempt' => $attempt,
                'statusLevel' => $payload['statusLevel'] ?? 'unknown',
                'statusMessage' => $payload['statusMessage'] ?? null,
                'followersSource' => $extracted['count_sources']['followers'] ?? null,
                'followingSource' => $extracted['count_sources']['following'] ?? null,
                'postsSource' => $extracted['count_sources']['posts'] ?? null,
                'visibleCountsComplete' => (bool) ($extracted['visible_counts_complete'] ?? false),
                'profileVisibility' => $extracted['profile_visibility'] ?? 'unknown',
                'isPrivate' => $extracted['is_private'] ?? null,
            ];

            $lastPayload = $payload;
            $lastExtracted = $extracted;

            if (($extracted['visible_counts_complete'] ?? false) || $this->extractedProfileVisibility($extracted) === 'private') {
                break;
            }

            if ($this->shouldStopGracefully() || (bool) ($payload['gracefullyStopped'] ?? false)) {
                $payload['statusLevel'] = 'partial';
                $payload['statusMessage'] = 'Instagram-Scan wurde beendet; die bis dahin ermittelten Grunddaten werden gespeichert.';
                break;
            }

            if ($attempt < self::MAX_VISIBLE_RETRY_ATTEMPTS) {
                usleep(1200000);
            }
        }

        return [
            $lastPayload,
            $lastExtracted,
            [
                'used_attempt' => count($attempts),
                'max_attempts' => self::MAX_VISIBLE_RETRY_ATTEMPTS,
                'visible_counts_complete' => (bool) ($lastExtracted['visible_counts_complete'] ?? false),
                'attempts' => $attempts,
            ],
        ];
    }

    private function scrapePortioned(
        string $username,
        ?callable $progress = null,
        ?TrackedPersonInstagramSnapshot $previousSnapshot = null,
    ): array
    {
        [$payload, $extracted, $attemptInfo] = $this->scrapeUntilVisibleCounts($username, $progress);
        $this->reportProgress($progress, [
            'phase' => 'profile',
            'percent' => 25,
            'message' => 'Grunddaten abgeschlossen.',
        ]);
        $phaseResults = [
            [
                'phase' => 'profile',
                'statusLevel' => $payload['statusLevel'] ?? 'unknown',
                'statusMessage' => $payload['statusMessage'] ?? null,
                'screenshotPath' => $this->scraper->resolvePublicStoragePath($payload['screenshotPath'] ?? null),
                'ok' => (bool) ($payload['ok'] ?? false),
            ],
        ];
        $phaseWarnings = [];

        if ($this->extractedProfileVisibility($extracted) === 'private') {
            $this->reportProgress($progress, [
                'phase' => 'profile',
                'percent' => 95,
                'message' => 'Privates Profil erkannt; Follower- und Gefolgt-Listen werden uebersprungen.',
            ]);

            $phaseWarnings[] = 'Privates Profil erkannt; Follower- und Gefolgt-Listen wurden uebersprungen. Fuer die Netzwerkerweiterung wird der Vorschlag-Scan genutzt.';
            $payload['warnings'] = array_values(array_unique(array_filter(array_merge(
                $payload['warnings'] ?? [],
                $phaseWarnings,
            ))));
            $payload['scrapePhases'] = $phaseResults;
            $attemptInfo['scan_mode'] = 'full';
            $attemptInfo['phases'] = $phaseResults;
            $attemptInfo['private_profile_detected'] = true;
            $attemptInfo['relationship_phases_skipped'] = 'private_profile';

            return [$payload, $extracted, $attemptInfo];
        }

        foreach ([
            'followers' => [
                'payload_key' => 'followersList',
                'start' => 26,
                'end' => 62,
                'expected' => $extracted['followers_count'] ?? 0,
                'expected_override' => 'expectedFollowerCount',
                'label' => 'Followerliste',
            ],
            'following' => [
                'payload_key' => 'followingList',
                'start' => 63,
                'end' => 95,
                'expected' => $extracted['following_count'] ?? 0,
                'expected_override' => 'expectedFollowingCount',
                'label' => 'Gefolgt-Liste',
            ],
        ] as $phase => $phaseConfig) {
            try {
                if ($this->shouldStopGracefully()) {
                    $payload['ok'] = false;
                    $payload['statusLevel'] = 'partial';
                    $payload['statusMessage'] = 'Instagram-Scan wurde beendet; bisherige Ergebnisse wurden gespeichert.';
                    $payload['gracefullyStopped'] = true;
                    $phaseWarnings[] = 'Instagram-Scan wurde ueber die Oberflaeche beendet. Bereits ermittelte Daten wurden gespeichert.';
                    break;
                }

                $this->reportProgress($progress, [
                    'phase' => $phase,
                    'percent' => $phaseConfig['start'],
                    'message' => $phaseConfig['label'].' wird gestartet.',
                ]);

                $phasePayload = $this->scraper->scrape(
                    $username,
                    $phase,
                    $progress,
                    $this->withActiveScanControl([
                        $phaseConfig['expected_override'] => max(0, (int) ($phaseConfig['expected'] ?? 0)),
                        'relationshipPrioritizedSearchUsernames' => [
                            $phase => $this->previousRelationshipUsernamesForSearch($previousSnapshot, $phase),
                        ],
                    ]),
                    $phaseConfig['start'],
                    $phaseConfig['end'],
                );
                $phaseList = data_get($phasePayload, 'profile.'.$phaseConfig['payload_key']);

                if (is_array($phaseList)) {
                    data_set($payload, 'profile.'.$phaseConfig['payload_key'], $phaseList);
                }

                $payload['notes'] = array_values(array_unique(array_filter(array_merge(
                    $payload['notes'] ?? [],
                    $phasePayload['notes'] ?? [],
                ))));
                $payload['warnings'] = array_values(array_unique(array_filter(array_merge(
                    $payload['warnings'] ?? [],
                    $phasePayload['warnings'] ?? [],
                ))));
                $phaseResults[] = [
                    'phase' => $phase,
                    'statusLevel' => $phasePayload['statusLevel'] ?? 'unknown',
                    'statusMessage' => $phasePayload['statusMessage'] ?? null,
                    'screenshotPath' => $this->scraper->resolvePublicStoragePath($phasePayload['screenshotPath'] ?? null),
                    'ok' => (bool) ($phasePayload['ok'] ?? false),
                    'count' => is_array($phaseList) ? (int) ($phaseList['count'] ?? 0) : 0,
                    'available' => is_array($phaseList) ? (bool) ($phaseList['available'] ?? false) : false,
                    'rateLimited' => is_array($phaseList) ? (bool) ($phaseList['rateLimited'] ?? false) : false,
                    'listTemporarilyUnavailable' => is_array($phaseList) ? (bool) ($phaseList['listTemporarilyUnavailable'] ?? false) : false,
                    'gracefullyStopped' => (bool) ($phasePayload['gracefullyStopped'] ?? data_get($phaseList, 'gracefullyStopped', false)),
                ];

                if ((bool) ($phasePayload['gracefullyStopped'] ?? data_get($phaseList, 'gracefullyStopped', false))) {
                    $payload['ok'] = false;
                    $payload['statusLevel'] = 'partial';
                    $payload['statusMessage'] = 'Instagram-Scan wurde beendet; bisherige Ergebnisse wurden gespeichert.';
                    $payload['gracefullyStopped'] = true;
                    $phaseWarnings[] = $phaseConfig['label'].' wurde ueber die Oberflaeche beendet. Bisherige Eintraege wurden gespeichert.';
                    break;
                }

                if (is_array($phaseList) && (bool) ($phaseList['rateLimited'] ?? false)) {
                    $phaseWarnings[] = $phaseConfig['label'].' wurde durch Instagram Rate-Limit blockiert. Weitere Listenphasen werden fuer diesen Lauf uebersprungen.';
                    break;
                }

                if (is_array($phaseList) && (bool) ($phaseList['listTemporarilyUnavailable'] ?? false)) {
                    $phaseWarnings[] = $phaseConfig['label'].' zeigte waehrend des Scans ploetzlich keine Profile mehr. Diese Liste wurde fuer diesen Lauf beendet.';
                }
            } catch (\Throwable $exception) {
                $phaseWarning = sprintf(
                    'Instagram-%s-Phase fehlgeschlagen: %s',
                    $phase === 'followers' ? 'Followerliste' : 'Gefolgt-Liste',
                    $exception->getMessage(),
                );
                $phaseWarnings[] = $phaseWarning;
                $phaseResults[] = [
                    'phase' => $phase,
                    'statusLevel' => 'error',
                    'statusMessage' => $phaseWarning,
                    'ok' => false,
                ];
            }
        }

        $payload['warnings'] = array_values(array_unique(array_filter(array_merge(
            $payload['warnings'] ?? [],
            $phaseWarnings,
        ))));
        $payload['scrapePhases'] = $phaseResults;
        $extracted = $this->extractor->extract($payload);
        $attemptInfo['scan_mode'] = 'full';
        $attemptInfo['phases'] = $phaseResults;

        return [$payload, $extracted, $attemptInfo];
    }

    private function resolveStatusMessage(array $payload, array $attemptInfo): string
    {
        $statusMessage = $payload['statusMessage'] ?? ($payload['error'] ?? 'Instagram-Scrape fehlgeschlagen.');

        if (($attemptInfo['relationship_only'] ?? false) === true) {
            $relationship = (string) ($attemptInfo['relationship'] ?? '');
            $label = $relationship === 'followers' ? 'Followerliste' : 'Gefolgt-Liste';
            $phase = collect($attemptInfo['phases'] ?? [])->firstWhere('phase', $relationship) ?? [];

            if ((bool) ($payload['gracefullyStopped'] ?? false) || (bool) ($phase['gracefullyStopped'] ?? false)) {
                return $label.' wurde beendet; bisher gefundene Eintraege wurden gespeichert.';
            }

            if ((bool) ($phase['rateLimited'] ?? false)) {
                return $label.' wurde durch Instagram Rate-Limit blockiert; bisher bekannte Eintraege bleiben gespeichert.';
            }

            if ((bool) ($phase['listTemporarilyUnavailable'] ?? false)) {
                return $label.' zeigte waehrend des Scans ploetzlich keine Profile mehr; bisher bekannte Eintraege bleiben gespeichert.';
            }

            if ((bool) ($phase['available'] ?? false)) {
                return $label.' separat gescannt: '.number_format((int) ($phase['count'] ?? 0), 0, ',', '.').' Eintraege gefunden.';
            }

            return $label.' konnte in diesem Einzel-Scan nicht vollstaendig geladen werden. '.$statusMessage;
        }

        if ((bool) ($payload['gracefullyStopped'] ?? false) || (bool) ($attemptInfo['gracefully_stopped'] ?? false)) {
            return 'Instagram-Scan wurde beendet; bisher ermittelte Daten wurden gespeichert.';
        }

        if (($attemptInfo['private_profile_detected'] ?? false) === true) {
            return 'Privates Instagram-Profil erkannt; Follower- und Gefolgt-Listen wurden uebersprungen.';
        }

        if (($attemptInfo['scan_mode'] ?? null) === 'mini') {
            if (($attemptInfo['visible_counts_complete'] ?? false) || ($attemptInfo['counts_found'] ?? false)) {
                return 'Instagram-Mini-Scan abgeschlossen.';
            }

            return 'Instagram-Mini-Scan fehlgeschlagen: Instagram hat in diesem Lauf keine oeffentlichen Kennzahlen im DOM oder HTML geliefert.';
        }

        if (! ($attemptInfo['visible_counts_complete'] ?? false)) {
            return $statusMessage.' Sichtbare Kennzahlen konnten nach '.($attemptInfo['used_attempt'] ?? 1).' Versuch(en) nicht stabil geladen werden.';
        }

        if (($attemptInfo['used_attempt'] ?? 1) > 1) {
            return $statusMessage.' Sichtbare Kennzahlen wurden erst nach '.($attemptInfo['used_attempt']).' Versuch(en) geladen.';
        }

        return $statusMessage;
    }

    private function reportProgress(?callable $progress, array $payload): void
    {
        $this->assertActiveScanCurrent();

        if (! $progress) {
            return;
        }

        $progress([
            'phase' => $payload['phase'] ?? 'analysis',
            'percent' => max(0, min(100, (int) ($payload['percent'] ?? 0))),
            'message' => (string) ($payload['message'] ?? 'Instagram-Analyse laeuft.'),
            'loaded' => $payload['loaded'] ?? null,
            'expected' => $payload['expected'] ?? null,
        ]);
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

    private function buildStoredPayload(
        array $payload,
        array $extracted,
        array $detectedChanges,
        array $attemptInfo,
        ?string $profileImageHash,
    ): array {
        $payload = $this->stripRelationshipItemsFromPayload($payload, $extracted);
        $existingWarnings = array_values(array_filter(array_merge(
            $payload['warnings'] ?? [],
            $extracted['count_warnings'] ?? [],
        )));

        $payload['warnings'] = array_values(array_unique($existingWarnings));
        $payload['extractedProfile'] = [
            'fullName' => $extracted['full_name'] ?? null,
            'biography' => $extracted['biography'] ?? null,
            'isPrivate' => $extracted['is_private'] ?? null,
            'profileVisibility' => $extracted['profile_visibility'] ?? 'unknown',
            'postsCount' => $extracted['posts_count'] ?? null,
            'followersCount' => $extracted['followers_count'] ?? null,
            'followingCount' => $extracted['following_count'] ?? null,
            'countSources' => $extracted['count_sources'] ?? [],
            'countWarnings' => $extracted['count_warnings'] ?? [],
            'visibleCountsComplete' => (bool) ($extracted['visible_counts_complete'] ?? false),
            'followersList' => $this->summarizeRelationshipListForPayload($extracted['followers_list'] ?? []),
            'followingList' => $this->summarizeRelationshipListForPayload($extracted['following_list'] ?? []),
            'profileImageHash' => $profileImageHash,
            'detectedChanges' => $detectedChanges,
        ];
        $payload['analysisPolicy'] = [
            'scanMode' => $attemptInfo['scan_mode'] ?? 'full',
            'counts' => ($attemptInfo['scan_mode'] ?? 'full') === 'mini'
                ? 'public-visible-or-html-profile-data'
                : 'visible-only',
            'retryAttempts' => $attemptInfo['attempts'] ?? [],
            'scrapePhases' => $attemptInfo['phases'] ?? [],
            'usedAttempt' => $attemptInfo['used_attempt'] ?? 1,
            'maxAttempts' => $attemptInfo['max_attempts'] ?? self::MAX_VISIBLE_RETRY_ATTEMPTS,
            'countsFound' => (bool) ($attemptInfo['counts_found'] ?? false),
            'preservedProfileFields' => $attemptInfo['preserved_profile_fields'] ?? [],
            'invalidComparisonBaseline' => ! $this->extractedHasComparableProfileValue($extracted, $profileImageHash),
            'monitoringOnly' => 'public-visible-data',
        ];

        return $payload;
    }

    private function stripRelationshipItemsFromPayload(array $payload, array $extracted): array
    {
        foreach ([
            'followersList' => 'followers_list',
            'followingList' => 'following_list',
        ] as $payloadKey => $extractedKey) {
            if (! is_array(data_get($payload, 'profile.'.$payloadKey))) {
                continue;
            }

            data_set(
                $payload,
                'profile.'.$payloadKey,
                $this->summarizeRelationshipListForPayload($extracted[$extractedKey] ?? []),
            );
        }

        return $payload;
    }

    private function summarizeRelationshipListForPayload(array $relationshipList): array
    {
        return [
            'attempted' => (bool) ($relationshipList['attempted'] ?? false),
            'available' => (bool) ($relationshipList['available'] ?? false),
            'complete' => (bool) ($relationshipList['complete'] ?? false),
            'count' => (int) ($relationshipList['count'] ?? count($relationshipList['items'] ?? [])),
            'activeCount' => (int) ($relationshipList['activeCount'] ?? $relationshipList['count'] ?? count($relationshipList['items'] ?? [])),
            'observedCount' => (int) ($relationshipList['observedCount'] ?? count($relationshipList['observedItems'] ?? $relationshipList['items'] ?? [])),
            'knownCount' => (int) ($relationshipList['knownCount'] ?? $relationshipList['count'] ?? count($relationshipList['items'] ?? [])),
            'allKnownCount' => (int) ($relationshipList['allKnownCount'] ?? count($relationshipList['allKnownItems'] ?? $relationshipList['items'] ?? [])),
            'addedCount' => (int) ($relationshipList['addedCount'] ?? count($relationshipList['addedItems'] ?? [])),
            'removedCount' => (int) ($relationshipList['removedCount'] ?? count($relationshipList['removedItems'] ?? [])),
            'removedHistoryCount' => (int) ($relationshipList['removedHistoryCount'] ?? count($relationshipList['removedHistoryItems'] ?? [])),
            'currentlyRemovedCount' => (int) ($relationshipList['currentlyRemovedCount'] ?? count($relationshipList['currentlyRemovedItems'] ?? [])),
            'trimmed' => (bool) ($relationshipList['trimmed'] ?? false),
            'maxItems' => (int) ($relationshipList['maxItems'] ?? 0),
            'expectedCount' => (int) ($relationshipList['expectedCount'] ?? 0),
            'openAttempts' => (int) ($relationshipList['openAttempts'] ?? 0),
            'scrollRounds' => (int) ($relationshipList['scrollRounds'] ?? 0),
            'noProgressReopenLimit' => (int) ($relationshipList['noProgressReopenLimit'] ?? 0),
            'searchAttempted' => (bool) ($relationshipList['searchAttempted'] ?? false),
            'searchInputAvailable' => (bool) ($relationshipList['searchInputAvailable'] ?? false),
            'searchQueries' => array_values(is_array($relationshipList['searchQueries'] ?? null) ? $relationshipList['searchQueries'] : []),
            'searchRounds' => (int) ($relationshipList['searchRounds'] ?? 0),
            'searchAddedCount' => (int) ($relationshipList['searchAddedCount'] ?? 0),
            'searchStopReason' => $relationshipList['searchStopReason'] ?? null,
            'searchMaxDepth' => (int) ($relationshipList['searchMaxDepth'] ?? 0),
            'searchExpandedQueryCount' => (int) ($relationshipList['searchExpandedQueryCount'] ?? 0),
            'reason' => $relationshipList['reason'] ?? null,
            'rateLimited' => (bool) ($relationshipList['rateLimited'] ?? false),
            'rateLimitText' => $relationshipList['rateLimitText'] ?? null,
            'listTemporarilyUnavailable' => (bool) ($relationshipList['listTemporarilyUnavailable'] ?? false),
            'gracefullyStopped' => (bool) ($relationshipList['gracefullyStopped'] ?? false),
            'itemsPath' => $relationshipList['itemsPath'] ?? null,
            'itemsPreview' => array_slice($relationshipList['items'] ?? [], 0, 25),
            'observedPreview' => array_slice($relationshipList['observedItems'] ?? [], 0, 25),
            'addedPreview' => array_slice($relationshipList['addedItems'] ?? [], 0, 25),
            'removedPreview' => array_slice($relationshipList['removedItems'] ?? [], 0, 25),
            'removedHistoryPreview' => array_slice($relationshipList['removedHistoryItems'] ?? [], 0, 25),
            'currentlyRemovedPreview' => array_slice($relationshipList['currentlyRemovedItems'] ?? [], 0, 25),
        ];
    }

    private function appendPersistedWarnings(array $payload, array $persistedWarnings): array
    {
        if ($persistedWarnings === []) {
            return $payload;
        }

        $payload['persistedWarnings'] = array_values(array_unique($persistedWarnings));

        return $payload;
    }

    private function previousComparableSnapshot(TrackedPerson $trackedPerson): ?TrackedPersonInstagramSnapshot
    {
        return $trackedPerson->instagramSnapshots()
            ->latest('analyzed_at')
            ->limit(100)
            ->get()
            ->first(fn (TrackedPersonInstagramSnapshot $snapshot): bool => $this->snapshotCanBeComparisonBaseline($snapshot));
    }

    private function snapshotCanBeComparisonBaseline(TrackedPersonInstagramSnapshot $snapshot): bool
    {
        if ((bool) data_get($snapshot->raw_payload, 'analysisPolicy.invalidComparisonBaseline', false)) {
            return false;
        }

        foreach ($this->profileComparisonFields() as $field) {
            if ($snapshot->{$field} !== null && $snapshot->{$field} !== '') {
                return true;
            }
        }

        return $snapshot->profile_image_hash !== null && $snapshot->profile_image_hash !== '';
    }

    private function preserveMissingProfileValues(
        array $extracted,
        ?TrackedPersonInstagramSnapshot $previousSnapshot,
        array &$preservedFields,
    ): array {
        $preservedFields = [];

        if (! $previousSnapshot) {
            return $extracted;
        }

        foreach ($this->profileComparisonFields() as $field) {
            $currentValue = $extracted[$field] ?? null;
            $previousValue = $previousSnapshot->{$field};

            if (($currentValue !== null && $currentValue !== '') || ($previousValue === null || $previousValue === '')) {
                continue;
            }

            $extracted[$field] = $previousValue;
            $preservedFields[] = $field;
        }

        return $extracted;
    }

    private function preserveUnreliableProfileVisibility(
        array $extracted,
        ?TrackedPersonInstagramSnapshot $previousSnapshot,
        array $payload,
        array $attemptInfo,
        array &$preservedFields,
    ): array {
        if (! $previousSnapshot || $this->scanCanUpdateProfileVisibility($payload, $attemptInfo)) {
            return $extracted;
        }

        $previousVisibility = $this->snapshotProfileVisibility($previousSnapshot);

        if (! in_array($previousVisibility, ['public', 'private'], true)) {
            return $extracted;
        }

        $extracted['profile_visibility'] = $previousVisibility;
        $extracted['is_private'] = $previousVisibility === 'private';

        foreach (['profile_visibility', 'is_private'] as $field) {
            if (! in_array($field, $preservedFields, true)) {
                $preservedFields[] = $field;
            }
        }

        return $extracted;
    }

    private function scanCanUpdateProfileVisibility(array $payload, array $attemptInfo): bool
    {
        $statusLevel = Str::lower(trim((string) ($payload['statusLevel'] ?? '')));

        if ($statusLevel !== 'success') {
            return false;
        }

        if (array_key_exists('ok', $payload) && ! (bool) $payload['ok']) {
            return false;
        }

        if ((bool) ($payload['gracefullyStopped'] ?? false) || (bool) ($attemptInfo['gracefully_stopped'] ?? false)) {
            return false;
        }

        foreach (($attemptInfo['phases'] ?? []) as $phase) {
            if (! is_array($phase)) {
                continue;
            }

            $phaseStatus = Str::lower(trim((string) ($phase['statusLevel'] ?? 'success')));

            if (
                $phaseStatus !== 'success'
                || (bool) ($phase['rateLimited'] ?? false)
                || (bool) ($phase['gracefullyStopped'] ?? false)
                || (bool) ($phase['listTemporarilyUnavailable'] ?? false)
            ) {
                return false;
            }
        }

        return true;
    }

    private function profileComparisonFields(): array
    {
        return [
            'full_name',
            'biography',
            'posts_count',
            'followers_count',
            'following_count',
            'profile_image_url',
        ];
    }

    private function extractedHasComparableProfileValue(array $extracted, ?string $profileImageHash): bool
    {
        foreach ($this->profileComparisonFields() as $field) {
            $value = $extracted[$field] ?? null;

            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return $profileImageHash !== null && $profileImageHash !== '';
    }

    private function detectSnapshotChanges(
        ?TrackedPersonInstagramSnapshot $previousSnapshot,
        array $extracted,
        ?string $profileImageHash,
        bool $monitoringOnly = false,
    ): array {
        if (! $previousSnapshot) {
            return [];
        }

        $labels = [
            'posts_count' => 'Beitraege',
            'followers_count' => 'Follower',
            'following_count' => 'Gefolgt',
        ];

        if (! $monitoringOnly) {
            $labels = [
                'full_name' => 'Name',
                'biography' => 'Bio',
                ...$labels,
            ];
        }
        $changes = [];
        $previousVisibility = $this->snapshotProfileVisibility($previousSnapshot);
        $currentVisibility = $this->extractedProfileVisibility($extracted);

        if (
            $currentVisibility !== 'unknown'
            && $previousVisibility !== 'unknown'
            && $this->valuesDiffer($previousVisibility, $currentVisibility)
        ) {
            $changes[] = [
                'field' => 'profile_visibility',
                'label' => 'Profilstatus',
                'before' => $previousVisibility,
                'after' => $currentVisibility,
            ];
        }

        foreach ($labels as $field => $label) {
            $before = $previousSnapshot->{$field};
            $after = $extracted[$field] ?? null;

            if ($after === null || ! $this->valuesDiffer($before, $after)) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $label,
                'before' => $before,
                'after' => $after,
            ];
        }

        if (
            $profileImageHash !== null
            && $this->valuesDiffer($previousSnapshot->profile_image_hash, $profileImageHash)
        ) {
            $changes[] = [
                'field' => 'profile_image_hash',
                'label' => 'Profilbild',
                'before' => $previousSnapshot->profile_image_hash,
                'after' => $profileImageHash,
            ];
        }

        foreach ([
            'followers_list' => 'Followerliste',
            'following_list' => 'Gefolgt-Liste',
        ] as $key => $label) {
            $relationshipList = $extracted[$key] ?? [];
            $addedItems = array_values($relationshipList['addedItems'] ?? []);
            $removedItems = array_values($relationshipList['removedItems'] ?? []);

            if ($addedItems !== []) {
                $changes[] = [
                    'field' => $key.'_added',
                    'label' => $label.' hinzugefuegt',
                    'before' => null,
                    'after' => implode(', ', array_map(
                        fn (array $item) => '@'.($item['username'] ?? ''),
                        array_slice($addedItems, 0, 50),
                    )),
                    'count' => count($addedItems),
                ];
            }

            if ($removedItems !== []) {
                $changes[] = [
                    'field' => $key.'_removed',
                    'label' => $label.' entfernt',
                    'before' => implode(', ', array_map(
                        fn (array $item) => '@'.($item['username'] ?? ''),
                        array_slice($removedItems, 0, 50),
                    )),
                    'after' => null,
                    'count' => count($removedItems),
                ];
            }
        }

        return $changes;
    }

    private function snapshotProfileVisibility(?TrackedPersonInstagramSnapshot $snapshot): string
    {
        if (! $snapshot) {
            return 'unknown';
        }

        $visibility = data_get($snapshot->raw_payload, 'extractedProfile.profileVisibility');

        if (in_array($visibility, ['public', 'private'], true)) {
            return $visibility;
        }

        $isPrivate = data_get($snapshot->raw_payload, 'extractedProfile.isPrivate');

        if ($isPrivate === true) {
            return 'private';
        }

        if ($isPrivate === false) {
            return 'public';
        }

        return 'unknown';
    }

    private function extractedProfileVisibility(array $extracted): string
    {
        $visibility = $extracted['profile_visibility'] ?? null;

        if (in_array($visibility, ['public', 'private'], true)) {
            return $visibility;
        }

        if (($extracted['is_private'] ?? null) === true) {
            return 'private';
        }

        if (($extracted['is_private'] ?? null) === false) {
            return 'public';
        }

        return 'unknown';
    }

    private function valuesDiffer(mixed $before, mixed $after): bool
    {
        if (is_string($before)) {
            $before = trim($before);
        }

        if (is_string($after)) {
            $after = trim($after);
        }

        return $before !== $after;
    }

    private function storeRelationshipListArtifacts(
        TrackedPerson $trackedPerson,
        TrackedPersonInstagramSnapshot $snapshot,
        array $extracted,
        array &$persistedWarnings,
        ?TrackedPersonInstagramSnapshot $previousSnapshot = null,
    ): array {
        $directory = 'tracked-people/'.$trackedPerson->id.'/instagram/'.$snapshot->analyzed_at->format('YmdHis').'-'.$snapshot->id.'/relationships';

        foreach ([
            'followers_list' => 'followers',
            'following_list' => 'following',
        ] as $extractedKey => $filename) {
            $relationshipList = $extracted[$extractedKey] ?? null;

            if (! is_array($relationshipList)) {
                continue;
            }

            $observedItems = $this->normalizeRelationshipItems($relationshipList['items'] ?? []);
            $previousState = $this->loadPreviousRelationshipState($previousSnapshot, $filename);
            $previousItems = $previousState['items'];
            $previousRemovedHistoryItems = $previousState['removedHistoryItems'];
            $currentIsComplete = (bool) ($relationshipList['complete'] ?? false);
            $analyzedAt = optional($snapshot->analyzed_at)->toIso8601String();
            $preserveExistingState = (bool) ($relationshipList['preserveExistingState'] ?? false);
            unset($relationshipList['preserveExistingState']);

            if ($preserveExistingState) {
                $observedItems = $previousItems;
                $activeItems = $this->sortRelationshipItemsNewestFirst($previousItems);
                $addedItems = [];
                $removedItems = [];
                $removedHistoryItems = $this->sortRelationshipItemsNewestFirst(
                    $previousRemovedHistoryItems,
                    ['removedAt', 'lastSeenAt', 'firstSeenAt'],
                );
            } else {
                $observedItems = $this->stampObservedRelationshipItems($observedItems, $previousItems, $analyzedAt);
                $activeItems = $this->sortRelationshipItemsNewestFirst(
                    $this->mergeRelationshipItems($previousItems, $observedItems, $currentIsComplete),
                );
                $addedItems = $this->sortRelationshipItemsNewestFirst(
                    $this->diffRelationshipItems($observedItems, $previousItems),
                );
                $removedItems = $currentIsComplete
                    ? $this->stampRemovedRelationshipItems(
                        $this->diffRelationshipItems($previousItems, $observedItems),
                        $analyzedAt,
                    )
                    : [];
                $removedHistoryItems = $this->sortRelationshipItemsNewestFirst(
                    $this->mergeRelationshipItemSets($previousRemovedHistoryItems, $removedItems),
                    ['removedAt', 'lastSeenAt', 'firstSeenAt'],
                );
            }
            $currentlyRemovedItems = $this->sortRelationshipItemsNewestFirst(
                $this->diffRelationshipItems($removedHistoryItems, $activeItems),
                ['removedAt', 'lastSeenAt', 'firstSeenAt'],
            );
            $allKnownItems = $this->sortRelationshipItemsNewestFirst(
                $this->mergeRelationshipItemSets($activeItems, $removedHistoryItems),
            );

            $relationshipList['observedCount'] = count($observedItems);
            $relationshipList['activeCount'] = count($activeItems);
            $relationshipList['knownCount'] = count($activeItems);
            $relationshipList['allKnownCount'] = count($allKnownItems);
            $relationshipList['count'] = count($activeItems);
            $relationshipList['items'] = $activeItems;
            $relationshipList['observedItems'] = $observedItems;
            $relationshipList['allKnownItems'] = $allKnownItems;
            $relationshipList['addedItems'] = $addedItems;
            $relationshipList['removedItems'] = $removedItems;
            $relationshipList['removedHistoryItems'] = $removedHistoryItems;
            $relationshipList['currentlyRemovedItems'] = $currentlyRemovedItems;
            $relationshipList['addedCount'] = count($addedItems);
            $relationshipList['removedCount'] = count($removedItems);
            $relationshipList['removedHistoryCount'] = count($removedHistoryItems);
            $relationshipList['currentlyRemovedCount'] = count($currentlyRemovedItems);
            $relationshipList['trimmed'] = $currentIsComplete && $removedItems !== [];

            if ($observedItems === [] && $activeItems === [] && $removedHistoryItems === []) {
                $extracted[$extractedKey] = $relationshipList;
                continue;
            }

            $relativePath = $directory.'/'.$filename.'.json';
            $filePayload = [
                'trackedPersonId' => $trackedPerson->id,
                'snapshotId' => $snapshot->id,
                'instagramUsername' => $snapshot->instagram_username,
                'type' => $filename,
                'count' => count($activeItems),
                'activeCount' => count($activeItems),
                'observedCount' => count($observedItems),
                'knownCount' => count($activeItems),
                'allKnownCount' => count($allKnownItems),
                'addedCount' => count($addedItems),
                'removedCount' => count($removedItems),
                'removedHistoryCount' => count($removedHistoryItems),
                'currentlyRemovedCount' => count($currentlyRemovedItems),
                'complete' => $currentIsComplete,
                'trimmed' => (bool) $relationshipList['trimmed'],
                'expectedCount' => (int) ($relationshipList['expectedCount'] ?? 0),
                'maxItems' => (int) ($relationshipList['maxItems'] ?? 0),
                'openAttempts' => (int) ($relationshipList['openAttempts'] ?? 0),
                'scrollRounds' => (int) ($relationshipList['scrollRounds'] ?? 0),
                'noProgressReopenLimit' => (int) ($relationshipList['noProgressReopenLimit'] ?? 0),
                'searchAttempted' => (bool) ($relationshipList['searchAttempted'] ?? false),
                'searchInputAvailable' => (bool) ($relationshipList['searchInputAvailable'] ?? false),
                'searchQueries' => array_values(is_array($relationshipList['searchQueries'] ?? null) ? $relationshipList['searchQueries'] : []),
                'searchRounds' => (int) ($relationshipList['searchRounds'] ?? 0),
                'searchAddedCount' => (int) ($relationshipList['searchAddedCount'] ?? 0),
                'searchStopReason' => $relationshipList['searchStopReason'] ?? null,
                'searchMaxDepth' => (int) ($relationshipList['searchMaxDepth'] ?? 0),
                'searchExpandedQueryCount' => (int) ($relationshipList['searchExpandedQueryCount'] ?? 0),
                'reason' => $relationshipList['reason'] ?? null,
                'rateLimited' => (bool) ($relationshipList['rateLimited'] ?? false),
                'rateLimitText' => $relationshipList['rateLimitText'] ?? null,
                'listTemporarilyUnavailable' => (bool) ($relationshipList['listTemporarilyUnavailable'] ?? false),
                'gracefullyStopped' => (bool) ($relationshipList['gracefullyStopped'] ?? false),
                'scrapedAt' => optional($snapshot->analyzed_at)->toIso8601String(),
                'items' => $activeItems,
                'activeItems' => $activeItems,
                'observedItems' => $observedItems,
                'allKnownItems' => $allKnownItems,
                'addedItems' => $addedItems,
                'removedItems' => $removedItems,
                'removedHistoryItems' => $removedHistoryItems,
                'currentlyRemovedItems' => $currentlyRemovedItems,
            ];

            try {
                $stored = Storage::disk('public')->put(
                    $relativePath,
                    json_encode($filePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                );

                if (! $stored) {
                    throw new \RuntimeException('Storage::put lieferte false zurueck.');
                }

                $relationshipList['itemsPath'] = $relativePath;
            } catch (\Throwable $exception) {
                $persistedWarnings[] = sprintf(
                    '%s-Liste konnte nicht als JSON-Datei gespeichert werden: %s',
                    $filename === 'followers' ? 'Follower' : 'Gefolgt',
                    $exception->getMessage(),
                );
            }

            $extracted[$extractedKey] = $relationshipList;
        }

        return $extracted;
    }

    private function normalizeRelationshipItems(array $items): array
    {
        return collect($items)
            ->filter(fn ($item) => is_array($item) && filled($item['username'] ?? null))
            ->map(function (array $item) {
                $username = Str::lower(trim((string) ($item['username'] ?? '')));
                $normalized = [
                    'username' => $username,
                    'displayName' => filled($item['displayName'] ?? null) ? trim((string) $item['displayName']) : null,
                    'profileUrl' => filled($item['profileUrl'] ?? null) ? (string) $item['profileUrl'] : 'https://www.instagram.com/'.$username.'/',
                    'profileImageUrl' => filled($item['profileImageUrl'] ?? $item['profile_image_url'] ?? null)
                        ? (string) ($item['profileImageUrl'] ?? $item['profile_image_url'])
                        : null,
                    'profileVisibility' => in_array(($item['profileVisibility'] ?? null), ['public', 'private', 'unknown'], true)
                        ? $item['profileVisibility']
                        : null,
                    'isPrivate' => is_bool($item['isPrivate'] ?? null) ? $item['isPrivate'] : null,
                    'postsCount' => is_numeric($item['postsCount'] ?? null) ? (int) $item['postsCount'] : null,
                    'followersCount' => is_numeric($item['followersCount'] ?? null) ? (int) $item['followersCount'] : null,
                    'followingCount' => is_numeric($item['followingCount'] ?? null) ? (int) $item['followingCount'] : null,
                    'hoverCard' => is_array($item['hoverCard'] ?? null) ? $item['hoverCard'] : null,
                ];

                foreach (['firstSeenAt', 'lastSeenAt', 'removedAt'] as $timestampKey) {
                    $timestamp = $this->normalizeRelationshipTimestamp($item[$timestampKey] ?? null);

                    if ($timestamp !== null) {
                        $normalized[$timestampKey] = $timestamp;
                    }
                }

                return $normalized;
            })
            ->filter(fn (array $item) => $item['username'] !== '')
            ->unique('username')
            ->values()
            ->all();
    }

    private function normalizeRelationshipTimestamp(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function stampObservedRelationshipItems(array $observedItems, array $previousItems, ?string $analyzedAt): array
    {
        $previousByUsername = collect($previousItems)->keyBy('username');

        return collect($observedItems)
            ->map(function (array $item) use ($previousByUsername, $analyzedAt) {
                $previous = $previousByUsername->get($item['username'] ?? '');
                $firstSeenAt = $item['firstSeenAt'] ?? data_get($previous, 'firstSeenAt');

                if ($firstSeenAt === null && $previous === null) {
                    $firstSeenAt = $analyzedAt;
                }

                return array_filter([
                    ...$item,
                    'firstSeenAt' => $firstSeenAt,
                    'lastSeenAt' => $analyzedAt,
                    'removedAt' => null,
                ], fn ($value) => $value !== null);
            })
            ->values()
            ->all();
    }

    private function stampRemovedRelationshipItems(array $removedItems, ?string $removedAt): array
    {
        return collect($removedItems)
            ->map(fn (array $item) => array_filter([
                ...$item,
                'removedAt' => $item['removedAt'] ?? $removedAt,
            ], fn ($value) => $value !== null))
            ->values()
            ->all();
    }

    private function sortRelationshipItemsNewestFirst(array $items, array $timestampKeys = ['firstSeenAt', 'lastSeenAt', 'removedAt']): array
    {
        return collect($items)
            ->values()
            ->sortByDesc(function (array $item, int $index) use ($timestampKeys) {
                foreach ($timestampKeys as $timestampKey) {
                    $timestamp = $this->normalizeRelationshipTimestamp($item[$timestampKey] ?? null);

                    if ($timestamp !== null) {
                        return sprintf('%020d.%06d', strtotime($timestamp) ?: 0, 999999 - $index);
                    }
                }

                return sprintf('%020d.%06d', 0, 999999 - $index);
            })
            ->values()
            ->all();
    }

    private function loadPreviousRelationshipState(?TrackedPersonInstagramSnapshot $previousSnapshot, string $type): array
    {
        $emptyState = [
            'items' => [],
            'removedHistoryItems' => [],
        ];

        if (! $previousSnapshot) {
            return $emptyState;
        }

        $payloadKey = $type === 'followers' ? 'followersList' : 'followingList';
        $itemsPath = data_get($previousSnapshot->raw_payload, 'extractedProfile.'.$payloadKey.'.itemsPath');

        if (is_string($itemsPath) && $itemsPath !== '' && Storage::disk('public')->exists($itemsPath)) {
            try {
                $decoded = json_decode(Storage::disk('public')->get($itemsPath), true, flags: JSON_THROW_ON_ERROR);

                return [
                    'items' => $this->normalizeRelationshipPayloadItems(data_get($decoded, 'items', [])),
                    'removedHistoryItems' => $this->mergeRelationshipItemSets(
                        $this->normalizeRelationshipPayloadItems(data_get($decoded, 'removedHistoryItems', [])),
                        $this->normalizeRelationshipPayloadItems(data_get($decoded, 'removedItems', [])),
                        $this->normalizeRelationshipPayloadItems(data_get($decoded, 'currentlyRemovedItems', [])),
                    ),
                ];
            } catch (\Throwable) {
                return $emptyState;
            }
        }

        $legacyItems = data_get($previousSnapshot->raw_payload, 'extractedProfile.'.$payloadKey.'.items', []);

        if (is_array($legacyItems) && $legacyItems !== []) {
            return [
                'items' => $this->normalizeRelationshipPayloadItems($legacyItems),
                'removedHistoryItems' => $this->mergeRelationshipItemSets(
                    $this->normalizeRelationshipPayloadItems(data_get($previousSnapshot->raw_payload, 'extractedProfile.'.$payloadKey.'.removedHistoryPreview', [])),
                    $this->normalizeRelationshipPayloadItems(data_get($previousSnapshot->raw_payload, 'extractedProfile.'.$payloadKey.'.removedPreview', [])),
                    $this->normalizeRelationshipPayloadItems(data_get($previousSnapshot->raw_payload, 'extractedProfile.'.$payloadKey.'.currentlyRemovedPreview', [])),
                ),
            ];
        }

        return [
            'items' => $this->normalizeRelationshipPayloadItems(
                data_get($previousSnapshot->raw_payload, 'extractedProfile.'.$payloadKey.'.itemsPreview', []),
            ),
            'removedHistoryItems' => $this->mergeRelationshipItemSets(
                $this->normalizeRelationshipPayloadItems(data_get($previousSnapshot->raw_payload, 'extractedProfile.'.$payloadKey.'.removedHistoryPreview', [])),
                $this->normalizeRelationshipPayloadItems(data_get($previousSnapshot->raw_payload, 'extractedProfile.'.$payloadKey.'.removedPreview', [])),
                $this->normalizeRelationshipPayloadItems(data_get($previousSnapshot->raw_payload, 'extractedProfile.'.$payloadKey.'.currentlyRemovedPreview', [])),
            ),
        ];
    }

    private function previousRelationshipUsernamesForSearch(?TrackedPersonInstagramSnapshot $previousSnapshot, string $type): array
    {
        $state = $this->loadPreviousRelationshipState($previousSnapshot, $type);

        return collect($state['items'])
            ->pluck('username')
            ->filter()
            ->map(fn ($username) => Str::lower(ltrim(trim((string) $username), '@')))
            ->filter()
            ->unique()
            ->take(500)
            ->values()
            ->all();
    }

    private function preservedRelationshipListForExtraction(?TrackedPersonInstagramSnapshot $previousSnapshot, string $type): array
    {
        $state = $this->loadPreviousRelationshipState($previousSnapshot, $type);
        $items = $state['items'];
        $removedHistoryItems = $state['removedHistoryItems'];

        return [
            'attempted' => false,
            'available' => $items !== [],
            'complete' => false,
            'count' => count($items),
            'activeCount' => count($items),
            'observedCount' => count($items),
            'knownCount' => count($items),
            'allKnownCount' => count($items) + count($removedHistoryItems),
            'removedHistoryCount' => count($removedHistoryItems),
            'currentlyRemovedCount' => 0,
            'items' => $items,
            'observedItems' => $items,
            'removedHistoryItems' => $removedHistoryItems,
            'currentlyRemovedItems' => [],
            'preserveExistingState' => true,
            'reason' => $items === [] ? 'Nicht Teil dieses Einzel-Listen-Scans.' : 'Aus vorherigem Snapshot uebernommen.',
        ];
    }

    private function normalizeRelationshipPayloadItems(mixed $items): array
    {
        return is_array($items) ? $this->normalizeRelationshipItems($items) : [];
    }

    private function mergeRelationshipItems(array $previousItems, array $observedItems, bool $currentIsComplete): array
    {
        if ($currentIsComplete) {
            return $this->normalizeRelationshipItems($observedItems);
        }

        return $this->mergeRelationshipItemSets($previousItems, $observedItems);
    }

    private function mergeRelationshipItemSets(array ...$itemSets): array
    {
        return collect($itemSets)
            ->flatMap(fn (array $items) => $this->normalizeRelationshipItems($items))
            ->keyBy('username')
            ->values()
            ->all();
    }

    private function diffRelationshipItems(array $leftItems, array $rightItems): array
    {
        $rightUsernames = collect($rightItems)->pluck('username')->filter()->flip();

        return collect($leftItems)
            ->reject(fn (array $item) => $rightUsernames->has($item['username'] ?? null))
            ->values()
            ->all();
    }

    private function storeSnapshotMedia(
        TrackedPerson $trackedPerson,
        TrackedPersonInstagramSnapshot $snapshot,
        array $imageUrls,
        array &$persistedWarnings,
        ?TrackedPersonInstagramSnapshot $previousSnapshot = null,
    ): array {
        $storedMedia = [];
        $directory = 'tracked-people/'.$trackedPerson->id.'/instagram/'.$snapshot->analyzed_at->format('YmdHis').'-'.$snapshot->id;
        $previousProfileImageHash = $previousSnapshot?->profile_image_hash;
        $previousProfileImagePath = $previousSnapshot?->profile_image_path;

        foreach (array_values($imageUrls) as $index => $imageUrl) {
            $mediaType = $index === 0 ? 'profile' : 'image';
            $isProfileImage = $index === 0;
            $downloadedMedia = $this->downloadImage(
                $imageUrl,
                $directory,
                $mediaType,
                $index,
                $persistedWarnings,
                $isProfileImage ? $previousProfileImageHash : null,
                $isProfileImage ? $previousProfileImagePath : null,
            );

            if (($downloadedMedia['should_create_media_row'] ?? true) === false) {
                $storedMedia[] = [
                    'source_url' => $imageUrl,
                    'storage_path' => $downloadedMedia['storage_path'] ?? null,
                    'content_hash' => $downloadedMedia['content_hash'] ?? null,
                    'is_profile_image' => $isProfileImage,
                    'reused_existing' => true,
                ];

                continue;
            }

            $snapshot->media()->create([
                'tracked_person_id' => $trackedPerson->id,
                'media_type' => $mediaType,
                'is_profile_image' => $isProfileImage,
                'sort_order' => $index,
                'source_url' => $imageUrl,
                'storage_path' => $downloadedMedia['storage_path'] ?? null,
                'content_hash' => $downloadedMedia['content_hash'] ?? null,
            ]);

            $storedMedia[] = [
                'source_url' => $imageUrl,
                'storage_path' => $downloadedMedia['storage_path'] ?? null,
                'content_hash' => $downloadedMedia['content_hash'] ?? null,
                'is_profile_image' => $isProfileImage,
                'reused_existing' => false,
            ];
        }

        return $storedMedia;
    }

    private function downloadImage(
        string $imageUrl,
        string $directory,
        string $mediaType,
        int $index,
        array &$persistedWarnings,
        ?string $previousProfileImageHash = null,
        ?string $previousProfileImagePath = null,
    ): array {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
            ])->timeout(0)->retry(2, 500)->get($imageUrl);
        } catch (\Throwable $exception) {
            $persistedWarnings[] = 'Bild konnte nicht geladen werden: '.$exception->getMessage();

            return [
                'storage_path' => null,
                'content_hash' => null,
            ];
        }

        if (! $response->successful() || $response->body() === '') {
            $persistedWarnings[] = 'Bild-Download fehlgeschlagen fuer '.$imageUrl.' (HTTP '.$response->status().').';

            return [
                'storage_path' => null,
                'content_hash' => null,
            ];
        }

        $body = $response->body();
        $contentHash = hash('sha256', $body);

        if (
            $previousProfileImageHash !== null
            && $previousProfileImagePath !== null
            && $contentHash === $previousProfileImageHash
        ) {
            return [
                'storage_path' => $previousProfileImagePath,
                'content_hash' => $contentHash,
                'should_create_media_row' => false,
            ];
        }

        $extension = $this->guessExtension($response->header('Content-Type'), $imageUrl);
        $filename = $mediaType.'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'.'.$extension;
        $relativePath = $directory.'/'.$filename;

        Storage::disk('public')->put($relativePath, $body);

        return [
            'storage_path' => $relativePath,
            'content_hash' => $contentHash,
            'should_create_media_row' => true,
        ];
    }

    private function guessExtension(?string $contentType, string $imageUrl): string
    {
        $contentType = strtolower((string) $contentType);

        return match (true) {
            Str::contains($contentType, 'png') => 'png',
            Str::contains($contentType, 'webp') => 'webp',
            Str::contains($contentType, 'gif') => 'gif',
            Str::contains($contentType, 'jpeg'),
            Str::contains($contentType, 'jpg') => 'jpg',
            default => $this->guessExtensionFromUrl($imageUrl),
        };
    }

    private function guessExtensionFromUrl(string $imageUrl): string
    {
        $path = parse_url($imageUrl, PHP_URL_PATH);
        $extension = strtolower((string) pathinfo((string) $path, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)
            ? ($extension === 'jpeg' ? 'jpg' : $extension)
            : 'jpg';
    }
}
