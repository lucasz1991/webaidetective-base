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
    ) {
    }

    private ?array $activeScanControl = null;

    public function scan(
        TrackedPerson $contextPerson,
        InstagramProfile $profile,
        ?callable $progress = null,
        array $relationships = ['followers', 'following'],
    ): Collection {
        $username = $this->scraper->normalizeInstagramUsername($profile->username);

        if ($username === null) {
            throw new \RuntimeException('Fuer dieses Profil ist kein gueltiger Instagram-Username hinterlegt.');
        }

        $scanControl = $this->scanCoordinator->begin(
            $contextPerson->id,
            'Instagram-Profil-Listen-Scan @'.$username,
        );

        Cache::lock($this->lockKey($profile), 3600)->forceRelease();
        $lock = Cache::lock($this->lockKey($profile), 3600);

        if (! $lock->get()) {
            $this->scanCoordinator->finish($contextPerson->id, (int) $scanControl['generation']);
            throw new \RuntimeException('Fuer dieses Profil laeuft bereits ein Listen-Scan.');
        }

        $this->activeScanControl = $scanControl;

        try {
            return $this->scanWithLock($contextPerson, $profile, $username, $progress, $relationships);
        } finally {
            $lock->release();
            $this->scanCoordinator->finish($contextPerson->id, (int) $scanControl['generation']);
            $this->activeScanControl = null;
        }
    }

    private function scanWithLock(
        TrackedPerson $contextPerson,
        InstagramProfile $profile,
        string $username,
        ?callable $progress,
        array $relationships,
    ): Collection {
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

            $this->reportProgress($progress, [
                'phase' => $relationship,
                'percent' => $start,
                'message' => $label.' von @'.$username.' wird vorbereitet.',
            ]);

            $payload = $this->scraper->scrape(
                $username,
                $relationship,
                $progress,
                $this->withActiveScanControl([
                    $expectedOverride => max(0, (int) $expectedCount),
                ]),
                $start,
                $end,
            );
            $payload['analyzedAt'] = now('UTC')->toIso8601String();
            $extracted = $this->extractor->extract($payload);
            $listKey = $relationship === 'followers' ? 'followers_list' : 'following_list';
            $relationshipList = is_array($extracted[$listKey] ?? null) ? $extracted[$listKey] : [];

            $profile = $this->profileRelationshipStore->ensureProfile($username, [
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
                    'operation_mode' => $payload['operationMode'] ?? $relationship,
                    'profile_visibility' => $extracted['profile_visibility'] ?? null,
                    'visible_counts_complete' => (bool) ($extracted['visible_counts_complete'] ?? false),
                ],
            ]) ?: $profile;

            $scan = $this->profileRelationshipStore->storeDirectRelationshipListScan(
                $profile,
                $contextPerson,
                $relationship,
                $relationshipList,
                $payload,
                'network_map_profile_list',
            );

            if ($scan instanceof InstagramProfileListScan) {
                $this->scanCreditService->charge(
                    (int) $contextPerson->user_id,
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

    private function reportProgress(?callable $progress, array $payload): void
    {
        if ($progress) {
            $progress($payload);
        }
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
