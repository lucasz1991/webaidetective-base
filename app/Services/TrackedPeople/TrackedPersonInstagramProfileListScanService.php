<?php

namespace App\Services\TrackedPeople;

use App\Models\InstagramProfile;
use App\Models\InstagramProfileListScan;
use App\Models\TrackedPerson;
use App\Services\Billing\ScanCreditService;
use App\Services\Social\InstagramProfileDataExtractor;
use App\Services\Social\InstagramScraper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TrackedPersonInstagramProfileListScanService
{
    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly InstagramProfileDataExtractor $extractor,
        private readonly TrackedPersonInstagramScanCoordinator $scanCoordinator,
        private readonly InstagramProfileRelationshipStore $profileRelationshipStore,
        private readonly ScanCreditService $scanCreditService,
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

        $scanControl = $contextPerson
            ? $this->scanCoordinator->begin(
                $contextPerson->id,
                'Instagram-Profil-Listen-Scan @'.$username,
            )
            : null;

        Cache::lock($this->lockKey($profile), 3600)->forceRelease();
        $lock = Cache::lock($this->lockKey($profile), 3600);

        if (! $lock->get()) {
            if ($contextPerson && $scanControl) {
                $this->scanCoordinator->finish($contextPerson->id, (int) $scanControl['generation']);
            }

            throw new \RuntimeException('Fuer dieses Profil laeuft bereits ein Listen-Scan.');
        }

        $this->activeScanControl = $scanControl;

        try {
            return $this->scanWithLock($contextPerson, $profile, $username, $progress, $relationships, (int) $userId);
        } finally {
            $lock->release();

            if ($contextPerson && $scanControl) {
                $this->scanCoordinator->finish($contextPerson->id, (int) $scanControl['generation']);
            }

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
        $progress = $this->createLiveProgressCallback($contextPerson, $profile, $progress);
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
            ];

            if ($shouldResumeViaSearch) {
                // Ueberspringe Scroll-Phase und nutze alphabetische Suche
                $operationMode = $relationship === 'followers' ? 'followers-search' : 'following-search';
                $runtimeOverrides = [
                    ...$runtimeOverrides,
                    'relationshipSearchOnly' => true,
                    // Scroll-Phase auslassen / minimal halten
                    'relationshipListMaxScrollRounds' => 1,
                ];
            }

            $this->reportProgress($progress, [
                'phase' => $relationship,
                'percent' => $start,
                'message' => ($shouldResumeViaSearch
                    ? $label.' von @'.$username.' wird fortgesetzt (alphabetische Suche).'
                    : $label.' von @'.$username.' wird vorbereitet.'),
            ]);

            $payload = $this->scraper->scrape(
                $username,
                $operationMode,
                $progress,
                $this->withActiveScanControl($runtimeOverrides),
                $start,
                $end,
            );
            $payload['analyzedAt'] = now('UTC')->toIso8601String();
            $extracted = $this->extractor->extract($payload);
            $listKey = $relationship === 'followers' ? 'followers_list' : 'following_list';
            $relationshipList = is_array($extracted[$listKey] ?? null) ? $extracted[$listKey] : [];

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
            );

            if ($scan instanceof InstagramProfileListScan) {
                $this->scanCreditService->charge(
                    $userId,
                    $scan,
                    $payload,
                    'Instagram-'.($relationship === 'followers' ? 'Followerliste' : 'Gefolgt-Liste').' @'.$username,
                );
                $scans->push($scan);
            }

            if ($relationship === 'followers') {
                $observedFollowers = (int) ($relationshipList['observedCount'] ?? 0);
            } else {
                $observedFollowing = (int) ($relationshipList['observedCount'] ?? 0);
            }

            $this->reportProgress($progress, [
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

    private function lastListScanFor(InstagramProfile $profile, string $relationship): ?InstagramProfileListScan
    {
        return InstagramProfileListScan::query()
            ->where('instagram_profile_id', $profile->id)
            ->where('list_type', $relationship)
            ->latest('analyzed_at')
            ->first();
    }

    private function lastScanSuggestsResume(InstagramProfileListScan $lastScan, int $expectedCount): bool
    {
        if ($expectedCount <= 0) {
            return false;
        }

        $payload = is_array($lastScan->raw_payload) ? $lastScan->raw_payload : [];
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
        if ($progress) {
            $progress($payload);
        }
    }

    private function createLiveProgressCallback(
        ?TrackedPerson $contextPerson,
        InstagramProfile $profile,
        ?callable $progress = null,
    ): callable {
        return function (array $state) use ($contextPerson, $profile, $progress): void {
            try {
                $this->persistRelationshipPreviewProgress($contextPerson, $profile, $state);
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
    ): void {
        $this->assertActiveScanCurrent();

        $phase = Str::lower(trim((string) ($state['phase'] ?? '')));

        if (! in_array($phase, ['followers', 'following'], true)) {
            return;
        }

        $items = collect(is_array($state['relationshipItems'] ?? null) ? $state['relationshipItems'] : [])
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

        $this->profileRelationshipStore->syncObservedRelationshipPreview(
            $profile,
            $contextPerson,
            $phase,
            $items,
            now('UTC'),
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
        return 'instagram-profile-list-scan:'.$profile->id;
    }
}
