<?php

namespace App\Services\TrackedPeople;

use App\Models\InstagramProfile;
use App\Models\InstagramProfileScan;
use App\Services\Billing\ScanCreditService;
use App\Services\Social\InstagramProfileDataExtractor;
use App\Services\Social\InstagramScraper;
use App\Services\Support\DatabaseKeepAlive;
use Illuminate\Support\Facades\Cache;

class InstagramProfileScanService
{
    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly InstagramProfileDataExtractor $extractor,
        private readonly InstagramProfileRelationshipStore $profileRelationshipStore,
        private readonly InstagramProfileChangeNotificationService $profileChangeNotifications,
        private readonly TrackedPersonInstagramProfileListScanService $listScanService,
        private readonly TrackedPersonInstagramPostScanService $postScanService,
        private readonly ScanCreditService $scanCreditService,
    ) {}

    public function scan(
        InstagramProfile $profile,
        int $userId,
        bool $fullScan = false,
        ?callable $progress = null,
    ): array {
        $username = $this->scraper->normalizeInstagramUsername($profile->username);

        if ($username === null || $userId <= 0) {
            throw new \RuntimeException('Das Instagram-Profil kann nicht gescannt werden.');
        }

        $lock = Cache::lock('instagram-profile-scan:'.$profile->id, 3600);

        if (! $lock->get()) {
            throw new \RuntimeException('Fuer dieses Profil laeuft bereits ein Instagram-Scan.');
        }

        try {
            $previousMetrics = [
                'followers_count' => $profile->followers_count,
                'following_count' => $profile->following_count,
                'posts_count' => $profile->posts_count,
            ];
            $payload = $this->scraper->scrape(
                $username,
                $fullScan ? 'profile' : 'mini',
                $progress,
            );
            $extracted = $this->extractor->extract($payload);
            DatabaseKeepAlive::ping(0);

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
                'last_status_level' => $payload['statusLevel'] ?? 'unknown',
                'last_status_message' => $payload['statusMessage'] ?? 'Instagram-Profilscan abgeschlossen.',
                'last_scanned_at' => now('UTC'),
                'raw_profile' => [
                    'operation_mode' => $payload['operationMode'] ?? ($fullScan ? 'profile' : 'mini'),
                    'tracked_person_created' => false,
                ],
            ]) ?: $profile;
            $this->profileRelationshipStore->propagateProfileDataToLinkedTrackedPeople($profile);

            $listScans = collect();
            $postScan = null;
            $followUpFailures = [];

            if (
                $fullScan
                && ($profile->profile_visibility === 'public' || $profile->is_private === false)
            ) {
                try {
                    $listScans = $this->listScanService->scan(
                        null,
                        $profile,
                        $progress,
                        ['followers', 'following'],
                        $userId,
                    );
                } catch (\Throwable $exception) {
                    $followUpFailures[] = 'Listen-Scan fehlgeschlagen: '.$exception->getMessage();
                }

                try {
                    $postScan = $this->postScanService->scanProfile($profile, $userId, $progress);
                } catch (\Throwable $exception) {
                    $followUpFailures[] = 'Beitragsscan fehlgeschlagen: '.$exception->getMessage();
                }
            }

            $statusLevel = (string) ($payload['statusLevel'] ?? 'unknown');

            if ($followUpFailures !== []) {
                $statusLevel = 'partial';
            }
            $statusMessage = trim(
                (string) ($payload['statusMessage'] ?? 'Instagram-Profilscan abgeschlossen.')
                .($followUpFailures !== [] ? ' '.implode(' ', $followUpFailures) : ''),
            );
            $profile->forceFill([
                'last_status_level' => $statusLevel,
                'last_status_message' => $statusMessage,
                'last_scanned_at' => now('UTC'),
            ])->save();
            $this->profileRelationshipStore->propagateProfileDataToLinkedTrackedPeople($profile->fresh() ?: $profile);
            $profileScan = InstagramProfileScan::create([
                'instagram_profile_id' => $profile->id,
                'user_id' => $userId,
                'scan_mode' => $fullScan ? 'profile' : 'mini',
                'status_level' => $statusLevel,
                'status_message' => $statusMessage,
                'raw_payload' => $payload,
                'scanned_at' => now('UTC'),
            ]);
            $freshProfile = $profile->fresh() ?: $profile;
            $changes = $this->profileMetricChanges($previousMetrics, $freshProfile);

            $this->profileRelationshipStore->propagateProfileDataToLinkedTrackedPeople($freshProfile);
            $this->profileChangeNotifications->notifyProfileChanges(
                $freshProfile,
                $changes,
                $statusMessage,
                $profileScan->scanned_at,
                'profile-scan-'.$profileScan->id,
            );

            $this->scanCreditService->charge(
                $userId,
                $profileScan,
                $payload,
                ($fullScan ? 'Instagram-Vollanalyse' : 'Instagram-Mini-Scan').' @'.$username,
            );

            return [
                'profile' => $freshProfile,
                'scan' => $profileScan,
                'payload' => $payload,
                'listScans' => $listScans,
                'postScan' => $postScan,
                'statusLevel' => $statusLevel,
                'statusMessage' => $statusMessage,
            ];
        } finally {
            $lock->release();
        }
    }

    private function profileMetricChanges(array $previousMetrics, InstagramProfile $profile): array
    {
        $labels = [
            'followers_count' => 'Follower',
            'following_count' => 'Gefolgt',
            'posts_count' => 'Beitraege',
        ];
        $changes = [];

        foreach ($labels as $field => $label) {
            $before = $previousMetrics[$field] ?? null;
            $after = $profile->{$field};

            if ($before === null || $after === null || (int) $before === (int) $after) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $label,
                'before' => (int) $before,
                'after' => (int) $after,
            ];
        }

        return $changes;
    }
}
