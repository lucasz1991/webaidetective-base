<?php

namespace App\Services\TrackedPeople;

use App\Models\InstagramProfile;
use App\Models\InstagramProfileListScan;
use App\Models\InstagramProfileRelationship;
use App\Models\TrackedPerson;
use App\Services\Billing\ScanCreditService;
use App\Services\Social\InstagramProfileDataExtractor;
use App\Services\Social\InstagramScraper;
use App\Services\Support\DatabaseKeepAlive;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TrackedPersonInstagramProfileListScanService
{
    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly InstagramProfileDataExtractor $extractor,
        private readonly TrackedPersonInstagramScanCoordinator $scanCoordinator,
        private readonly InstagramProfileRelationshipStore $profileRelationshipStore,
        private readonly ScanCreditService $scanCreditService,
        private readonly InstagramScanEventStore $scanEvents,
    ) {}

    private ?array $activeScanControl = null;

    public function scan(
        ?TrackedPerson $contextPerson,
        InstagramProfile $profile,
        ?callable $progress = null,
        array $relationships = ['followers', 'following'],
        ?int $userId = null,
    ): Collection {
        $username = $this->scraper->normalizeInstagramUsername($profile->username);

        if ($username === null) {
            throw new \RuntimeException('Fuer dieses Profil ist kein gueltiger Instagram-Username hinterlegt.');
        }

        $userId = $contextPerson?->user_id ?: $userId;

        if (! $userId) {
            throw new \RuntimeException('Fuer den Profil-Listen-Scan fehlt der Benutzerkontext.');
        }

        $scanContextId = $contextPerson?->id ?: -1000000000 - (int) $profile->id;
        $scanControl = $this->scanCoordinator->begin(
            $scanContextId,
            'Instagram-Profil-Listen-Scan @'.$username,
            [
                'scan_type' => 'profile_list',
                'scan_context_key' => $contextPerson ? 'tracked-person:'.$contextPerson->id : 'instagram-profile:'.$profile->id,
                'target_username' => $username,
                'tracked_person_id' => $contextPerson?->id,
                'instagram_profile_id' => $profile->id,
                'user_id' => $userId,
            ],
        );

        Cache::lock($this->lockKey($profile), 3600)->forceRelease();
        $lock = Cache::lock($this->lockKey($profile), 3600);

        if (! $lock->get()) {
            $this->scanCoordinator->finish($scanContextId, (int) $scanControl['generation']);

            throw new \RuntimeException('Fuer dieses Profil laeuft bereits ein Listen-Scan.');
        }

        $this->activeScanControl = $scanControl;

        try {
            $scans = $this->scanWithLock($contextPerson, $profile, $username, $progress, $relationships, (int) $userId);

            $this->scanCoordinator->completeFromResult(
                $scanContextId,
                (int) $scanControl['generation'],
                $scans,
                'Instagram-Profil-Listen-Scan abgeschlossen.',
            );

            return $scans;
        } catch (\Throwable $exception) {
            $this->scanCoordinator->failForRetry(
                $scanContextId,
                (int) $scanControl['generation'],
                $exception->getMessage(),
            );

            throw $exception;
        } finally {
            $lock->release();
            $this->scanCoordinator->finish($scanContextId, (int) $scanControl['generation']);

            $this->activeScanControl = null;
        }
    }

    private function scanWithLock(
        ?TrackedPerson $contextPerson,
        InstagramProfile $profile,
        string $username,
        ?callable $progress,
        array $relationships,
        int $userId,
    ): Collection {
        $progress = $this->createLiveProgressCallback($contextPerson, $profile, $userId, $progress);
        $relationships = collect($relationships)
            ->map(fn ($relationship): string => Str::lower(trim((string) $relationship)))
            ->filter(fn (string $relationship): bool => in_array($relationship, ['followers', 'following'], true))
            ->unique()
            ->values();

        if ($relationships->isEmpty()) {
            throw new \InvalidArgumentException('Keine gueltigen Instagram-Listen fuer den Scan ausgewaehlt.');
        }

        $this->reportProgress($progress, [
            'phase' => 'profile-list',
            'percent' => 1,
            'message' => 'Profil-Listen-Scan fuer @'.$username.' wird vorbereitet.',
            'foundFollowers' => 0,
            'foundFollowing' => 0,
        ]);

        $scans = collect();
        $profile = $this->profileRelationshipStore->ensureProfile($username, [
            'display_name' => $profile->display_name,
            'full_name' => $profile->full_name,
            'profile_image_url' => $profile->profile_image_url,
            'profile_image_path' => $profile->profile_image_path,
            'profile_visibility' => $profile->profile_visibility,
            'followers_count' => $profile->followers_count,
            'following_count' => $profile->following_count,
            'posts_count' => $profile->posts_count,
        ]) ?: $profile;
        $observedFollowers = 0;
        $observedFollowing = 0;

        foreach ($relationships as $index => $relationship) {
            if ($this->shouldStopGracefully()) {
                break;
            }

            $this->assertActiveScanCurrent();

            $start = 3 + (int) floor(($index / max(1, $relationships->count())) * 92);
            $end = 3 + (int) floor((($index + 1) / max(1, $relationships->count())) * 92);
            $label = $relationship === 'followers' ? 'Followerliste' : 'Gefolgt-Liste';
            $expectedOverride = $relationship === 'followers' ? 'expectedFollowerCount' : 'expectedFollowingCount';
            $expectedCount = $relationship === 'followers' ? $profile->followers_count : $profile->following_count;
            $previousActiveItems = $this->activeRelationshipItems($profile, $relationship);
            $progressScan = $this->profileRelationshipStore->startDirectRelationshipListScan(
                $profile,
                $contextPerson,
                $relationship,
                'network_map_profile_list',
                $userId,
                $expectedCount !== null ? max(0, (int) $expectedCount) : null,
                $label.' von @'.$username.' wird gestartet; Fortschritt wird gespeichert.',
            );
            $scanProgress = $this->createScanEventProgressCallback(
                $progress,
                $progressScan,
                $username,
                $contextPerson?->id,
                $userId,
            );

            if ($progressScan) {
                $this->scanEvents->started(
                    'instagram_profile_list_scan',
                    $progressScan,
                    $username,
                    $contextPerson?->id,
                    $userId,
                    $label.' von @'.$username.' wurde gestartet.',
                    [
                        'phase' => $relationship,
                        'percent' => $start,
                        'expected' => $expectedCount,
                    ],
                );
            }

            // Resume-Entscheidung: Fuer grosse Listen (>= 250) und unvollstaendigen letzten Lauf direkt die alphabetische Suche nutzen
            $shouldResumeViaSearch = false;
            if ((int) $expectedCount >= 250) {
                $lastListScan = $this->lastListScanFor($profile, $relationship);
                if ($lastListScan) {
                    $shouldResumeViaSearch = $this->lastScanSuggestsResume($lastListScan, (int) $expectedCount);
                }
            }

            // Modus pro Beziehung festlegen
            $operationMode = $relationship;
            $runtimeOverrides = [
                $expectedOverride => max(0, (int) $expectedCount),
                'relationshipPrioritizedSearchUsernames' => [
                    $relationship => collect($previousActiveItems)->pluck('username')->values()->all(),
                ],
            ];

            if ($shouldResumeViaSearch) {
                // Ueberspringe Scroll-Phase und nutze alphabetische Suche
                $operationMode = $relationship === 'followers' ? 'followers-search' : 'following-search';
                $runtimeOverrides = [
                    ...$runtimeOverrides,
                    'relationshipSearchOnly' => true,
                ];
            }

            $this->reportProgress($scanProgress, [
                'phase' => $relationship,
                'percent' => $start,
                'message' => ($shouldResumeViaSearch
                    ? $label.' von @'.$username.' wird fortgesetzt (alphabetische Suche).'
                    : $label.' von @'.$username.' wird vorbereitet.'),
            ]);

            try {
                $payload = $this->scraper->scrape(
                    $username,
                    $operationMode,
                    $scanProgress,
                    $this->withActiveScanControl($runtimeOverrides),
                    $start,
                    $end,
                );
            } catch (\Throwable $exception) {
                $this->markProgressScanFailed(
                    $progressScan,
                    $username,
                    $contextPerson?->id,
                    $userId,
                    $relationship,
                    $exception,
                );

                throw $exception;
            }

            $payload['analyzedAt'] = now('UTC')->toIso8601String();
            $extracted = $this->extractor->extract($payload);
            $listKey = $relationship === 'followers' ? 'followers_list' : 'following_list';
            $relationshipList = is_array($extracted[$listKey] ?? null) ? $extracted[$listKey] : [];
            $relationshipList = $this->reconcileRelationshipList(
                $relationshipList,
                $previousActiveItems,
            );
            $this->refreshDatabaseConnection();

            $profileAttributes = [
                'display_name' => $extracted['full_name'] ?? $profile->display_name,
                'full_name' => $extracted['full_name'] ?? $profile->full_name,
                'biography' => $extracted['biography'] ?? $profile->biography,
                'profile_image_url' => $extracted['profile_image_url'] ?? $profile->profile_image_url,
                'is_private' => $extracted['is_private'] ?? $profile->is_private,
                'profile_visibility' => $extracted['profile_visibility'] ?? $profile->profile_visibility,
                'followers_count' => $extracted['followers_count'] ?? $profile->followers_count,
                'following_count' => $extracted['following_count'] ?? $profile->following_count,
                'posts_count' => $extracted['posts_count'] ?? $profile->posts_count,
                'last_status_level' => $payload['statusLevel'] ?? null,
                'last_status_message' => $payload['statusMessage'] ?? null,
                'last_scanned_at' => $payload['analyzedAt'],
                'raw_profile' => [
                    'operation_mode' => $payload['operationMode'] ?? $operationMode,
                    'profile_visibility' => $extracted['profile_visibility'] ?? null,
                    'visible_counts_complete' => (bool) ($extracted['visible_counts_complete'] ?? false),
                ],
            ];

            if (! $this->payloadCanUpdateProfileVisibility($payload, $relationshipList)) {
                unset($profileAttributes['is_private'], $profileAttributes['profile_visibility']);
            }

            $profile = $this->profileRelationshipStore->ensureProfile($username, $profileAttributes) ?: $profile;

            $scan = $this->profileRelationshipStore->storeDirectRelationshipListScan(
                $profile,
                $contextPerson,
                $relationship,
                $relationshipList,
                $payload,
                'network_map_profile_list',
                $userId,
                $progressScan,
            );

            if ($scan instanceof InstagramProfileListScan) {
                $this->scanCreditService->charge(
                    $userId,
                    $scan,
                    $payload,
                    'Instagram-'.($relationship === 'followers' ? 'Followerliste' : 'Gefolgt-Liste').' @'.$username,
                );
                $scans->push($scan);
                $this->scanEvents->finished(
                    'instagram_profile_list_scan',
                    $scan,
                    $username,
                    $contextPerson?->id,
                    $userId,
                    $scan->status_message ?: $label.' von @'.$username.' wurde gespeichert.',
                    [
                        'phase' => $relationship,
                        'percent' => $end,
                        'statusLevel' => $scan->status_level,
                        'loaded' => $scan->observed_count,
                        'expected' => $scan->expected_count,
                    ],
                );
            }

            if ($relationship === 'followers') {
                $observedFollowers = (int) ($relationshipList['observedCount'] ?? 0);
            } else {
                $observedFollowing = (int) ($relationshipList['observedCount'] ?? 0);
            }

            $this->reportProgress($scanProgress, [
                'phase' => $relationship,
                'percent' => $end,
                'message' => $label.' von @'.$username.' wurde gespeichert.',
                'foundFollowers' => $observedFollowers,
                'foundFollowing' => $observedFollowing,
            ]);

            if (
                ($extracted['profile_visibility'] ?? null) === 'private'
                || (bool) ($relationshipList['rateLimited'] ?? false)
                || (bool) ($relationshipList['gracefullyStopped'] ?? false)
                || (bool) ($payload['gracefullyStopped'] ?? false)
            ) {
                break;
            }
        }

        $this->reportProgress($progress, [
            'phase' => 'done',
            'percent' => 100,
            'message' => 'Profil-Listen-Scan fuer @'.$username.' abgeschlossen.',
        ]);

        return $scans->values();
    }

    private function createScanEventProgressCallback(
        ?callable $progress,
        ?InstagramProfileListScan $scan,
        string $username,
        ?int $trackedPersonId,
        int $userId,
    ): callable {
        return function (array $state) use ($progress, $scan, $username, $trackedPersonId, $userId): void {
            try {
                $this->scanEvents->progress(
                    'instagram_profile_list_scan',
                    $scan?->fresh() ?: $scan,
                    $username,
                    $trackedPersonId,
                    $userId,
                    $state,
                );
            } catch (\Throwable) {
                // Event-Logging darf den Listen-Scan nicht abbrechen.
            }

            if ($progress) {
                $progress($state);
            }
        };
    }

    private function markProgressScanFailed(
        ?InstagramProfileListScan $scan,
        string $username,
        ?int $trackedPersonId,
        int $userId,
        string $relationship,
        \Throwable $exception,
    ): void {
        if (! $scan) {
            return;
        }

        $payload = is_array($scan->raw_payload) ? $scan->raw_payload : [];
        $message = ($relationship === 'followers' ? 'Followerliste' : 'Gefolgt-Liste').' fehlgeschlagen: '.$exception->getMessage();

        $scan->forceFill([
            'status_level' => 'error',
            'status_message' => $message,
            'raw_payload' => [
                ...$payload,
                'ok' => false,
                'progressStatus' => 'failed',
                'error' => $exception->getMessage(),
                'failedAt' => now('UTC')->toIso8601String(),
            ],
            'scanned_at' => now('UTC'),
        ])->save();

        $this->scanEvents->failed(
            'instagram_profile_list_scan',
            $scan,
            $username,
            $trackedPersonId,
            $userId,
            $message,
            [
                'phase' => $relationship,
                'statusLevel' => 'error',
            ],
        );
    }

    private function lastListScanFor(InstagramProfile $profile, string $relationship): ?InstagramProfileListScan
    {
        $username = $this->scraper->normalizeInstagramUsername($profile->username);

        return InstagramProfileListScan::query()
            ->where(function ($query) use ($profile, $username): void {
                $query->where('instagram_profile_id', $profile->id);

                if ($username !== null && Schema::hasColumn('instagram_profile_list_scans', 'instagram_username')) {
                    $query->orWhere('instagram_username', $username);
                }
            })
            ->where('list_type', $relationship)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();
    }

    private function activeRelationshipItems(InstagramProfile $profile, string $relationship): array
    {
        return InstagramProfileRelationship::query()
            ->where('source_instagram_profile_id', $profile->id)
            ->where('list_type', $relationship)
            ->where('status', 'active')
            ->whereNull('removed_at')
            ->with('relatedInstagramProfile')
            ->get()
            ->map(function (InstagramProfileRelationship $storedRelationship): array {
                $related = $storedRelationship->relatedInstagramProfile;

                return [
                    'username' => $related?->username,
                    'displayName' => $related?->display_name ?: $storedRelationship->display_name_snapshot,
                    'profileUrl' => $related?->profile_url ?: $storedRelationship->profile_url_snapshot,
                    'profileImageUrl' => $related?->profile_image_url,
                    'profileVisibility' => $related?->profile_visibility,
                    'isPrivate' => $related?->is_private,
                    'followersCount' => $related?->followers_count,
                    'followingCount' => $related?->following_count,
                    'postsCount' => $related?->posts_count,
                    'firstSeenAt' => optional($storedRelationship->first_seen_at)->toIso8601String(),
                    'lastSeenAt' => optional($storedRelationship->last_seen_at)->toIso8601String(),
                ];
            })
            ->filter(fn (array $item): bool => filled($item['username'] ?? null))
            ->values()
            ->all();
    }

    private function reconcileRelationshipList(array $relationshipList, array $previousActiveItems): array
    {
        $normalize = fn ($value): string => Str::lower(ltrim(trim((string) $value), '@'));
        $observedItems = collect(is_array($relationshipList['items'] ?? null) ? $relationshipList['items'] : [])
            ->filter(fn ($item): bool => is_array($item) && filled($item['username'] ?? null))
            ->keyBy(fn (array $item): string => $normalize($item['username']));
        $previousItems = collect($previousActiveItems)
            ->filter(fn ($item): bool => is_array($item) && filled($item['username'] ?? null))
            ->keyBy(fn (array $item): string => $normalize($item['username']));
        $verifiedMissing = collect($relationshipList['verifiedMissingUsernames'] ?? [])
            ->filter(fn ($username): bool => is_scalar($username))
            ->map($normalize)
            ->filter()
            ->unique()
            ->flip();
        $removedItems = $previousItems
            ->filter(fn (array $item, string $username): bool => $verifiedMissing->has($username))
            ->values();
        $preservedItems = $previousItems
            ->reject(fn (array $item, string $username): bool => $verifiedMissing->has($username) || $observedItems->has($username))
            ->values();
        $activeItems = $previousItems
            ->reject(fn (array $item, string $username): bool => $verifiedMissing->has($username))
            ->merge($observedItems)
            ->keyBy(fn (array $item): string => $normalize($item['username']))
            ->values();

        return [
            ...$relationshipList,
            'available' => $activeItems->isNotEmpty(),
            'count' => $activeItems->count(),
            'activeCount' => $activeItems->count(),
            'knownCount' => $activeItems->count(),
            'observedCount' => $observedItems->count(),
            'items' => $activeItems->all(),
            'observedItems' => $observedItems->values()->all(),
            'preservedItems' => $preservedItems->all(),
            'addedItems' => $observedItems->reject(fn (array $item, string $username): bool => $previousItems->has($username))->values()->all(),
            'removedItems' => $removedItems->all(),
            'addedCount' => $observedItems->keys()->diff($previousItems->keys())->count(),
            'removedCount' => $removedItems->count(),
            'trimmed' => $removedItems->isNotEmpty(),
        ];
    }

    private function refreshDatabaseConnection(): void
    {
        DatabaseKeepAlive::reconnect();
    }

    private function lastScanSuggestsResume(InstagramProfileListScan $lastScan, int $expectedCount): bool
    {
        if ($expectedCount <= 0) {
            return false;
        }

        $payload = is_array($lastScan->raw_payload) ? $lastScan->raw_payload : [];

        if (filled(data_get($payload, 'resumeDismissedAt'))) {
            return false;
        }

        // Versuche verschiedene Pfade fuer observedCount zu lesen
        $observed = (int) (
            data_get($payload, 'profile.followersList.observedCount')
            ?? data_get($payload, 'profile.followingList.observedCount')
            ?? data_get($payload, 'followers_list.observedCount')
            ?? data_get($payload, 'following_list.observedCount')
            ?? 0
        );
        $rateLimited = (bool) (
            data_get($payload, 'profile.followersList.rateLimited')
            || data_get($payload, 'profile.followingList.rateLimited')
            || data_get($payload, 'followers_list.rateLimited')
            || data_get($payload, 'following_list.rateLimited')
            || data_get($payload, 'list.rateLimited')
            || data_get($payload, 'rateLimited')
        );
        $graceful = (bool) (
            data_get($payload, 'profile.followersList.gracefullyStopped')
            || data_get($payload, 'profile.followingList.gracefullyStopped')
            || data_get($payload, 'followers_list.gracefullyStopped')
            || data_get($payload, 'following_list.gracefullyStopped')
            || data_get($payload, 'list.gracefullyStopped')
            || data_get($payload, 'gracefullyStopped')
        );

        return ($observed > 0 && $observed < $expectedCount)
            || $rateLimited
            || $graceful;
    }

    private function reportProgress(?callable $progress, array $payload): void
    {
        DatabaseKeepAlive::ping(15);

        if ($progress) {
            $progress($payload);
        }
    }

    private function createLiveProgressCallback(
        ?TrackedPerson $contextPerson,
        InstagramProfile $profile,
        int $userId,
        ?callable $progress = null,
    ): callable {
        return function (array $state) use ($contextPerson, $profile, $userId, $progress): void {
            DatabaseKeepAlive::ping(15);

            try {
                $this->persistRelationshipPreviewProgress($contextPerson, $profile, $state, $userId);
            } catch (\Throwable) {
                // Live-Fortschritt darf den eigentlichen Listen-Scan nicht abbrechen.
            }

            if ($progress) {
                $progress($state);
            }
        };
    }

    private function persistRelationshipPreviewProgress(
        ?TrackedPerson $contextPerson,
        InstagramProfile $profile,
        array $state,
        int $userId,
    ): void {
        $this->assertActiveScanCurrent();

        $phase = Str::lower(trim((string) ($state['phase'] ?? '')));

        if (! in_array($phase, ['followers', 'following'], true)) {
            return;
        }

        $deltaItems = is_array($state['relationshipItemsDelta'] ?? null) ? $state['relationshipItemsDelta'] : [];
        $previewItems = is_array($state['relationshipItems'] ?? null) ? $state['relationshipItems'] : [];
        $items = collect($deltaItems !== [] ? $deltaItems : $previewItems)
            ->filter(fn ($item): bool => is_array($item) && filled($item['username'] ?? null))
            ->values()
            ->all();

        if ($items === []) {
            return;
        }

        $profile = $this->profileRelationshipStore->ensureProfile($profile->username, [
            'display_name' => $profile->display_name,
            'full_name' => $profile->full_name,
            'profile_image_url' => $profile->profile_image_url,
            'profile_image_path' => $profile->profile_image_path,
            'profile_visibility' => $profile->profile_visibility,
            'followers_count' => $profile->followers_count,
            'following_count' => $profile->following_count,
            'posts_count' => $profile->posts_count,
            'last_status_level' => 'partial',
            'last_status_message' => (string) ($state['message'] ?? 'Profil-Listen-Scan laeuft.'),
            'last_scanned_at' => now('UTC'),
        ]) ?: $profile;

        $evidence = [
            'source' => $deltaItems !== [] ? 'profile_list_live_delta' : 'profile_list_live_preview',
            'progress_stage' => is_scalar($state['stage'] ?? null) ? (string) $state['stage'] : null,
            'loaded' => is_numeric($state['loaded'] ?? null) ? (int) $state['loaded'] : null,
            'expected' => is_numeric($state['expected'] ?? null) ? (int) $state['expected'] : null,
        ];

        $this->profileRelationshipStore->syncObservedRelationshipPreview(
            $profile,
            $contextPerson,
            $phase,
            $items,
            now('UTC'),
            $evidence,
        );

        $this->profileRelationshipStore->syncLiveRelationshipListScan(
            $profile,
            $contextPerson,
            $phase,
            $items,
            now('UTC'),
            $evidence,
            $userId,
        );
    }

    private function payloadCanUpdateProfileVisibility(array $payload, array $relationshipList): bool
    {
        $statusLevel = Str::lower(trim((string) ($payload['statusLevel'] ?? '')));

        if ($statusLevel !== 'success') {
            return false;
        }

        if (array_key_exists('ok', $payload) && ! (bool) $payload['ok']) {
            return false;
        }

        return ! (bool) ($payload['gracefullyStopped'] ?? false)
            && ! (bool) ($relationshipList['rateLimited'] ?? false)
            && ! (bool) ($relationshipList['gracefullyStopped'] ?? false)
            && ! (bool) ($relationshipList['listTemporarilyUnavailable'] ?? false);
    }

    private function withActiveScanControl(array $runtimeConfigOverrides = []): array
    {
        if (! $this->activeScanControl) {
            return $runtimeConfigOverrides;
        }

        return [
            ...$runtimeConfigOverrides,
            '_scanControl' => $this->activeScanControl,
        ];
    }

    private function assertActiveScanCurrent(): void
    {
        if (! $this->activeScanControl) {
            return;
        }

        $this->scanCoordinator->assertCurrent(
            (int) $this->activeScanControl['trackedPersonId'],
            (int) $this->activeScanControl['generation'],
        );
    }

    private function shouldStopGracefully(): bool
    {
        if (! $this->activeScanControl) {
            return false;
        }

        return $this->scanCoordinator->shouldStopGracefully(
            (int) $this->activeScanControl['trackedPersonId'],
            (int) $this->activeScanControl['generation'],
        );
    }

    private function lockKey(InstagramProfile $profile): string
    {
        return 'instagram-profile-list-scan:'.($this->scraper->normalizeInstagramUsername($profile->username) ?: $profile->id);
    }
}
