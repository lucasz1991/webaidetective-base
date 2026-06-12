<?php

namespace App\Services\TrackedPeople;

use App\Models\InstagramProfile;
use App\Services\Social\InstagramProfileDataExtractor;
use App\Services\Social\InstagramScraper;
use Illuminate\Support\Facades\Cache;

class InstagramProfileScanService
{
    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly InstagramProfileDataExtractor $extractor,
        private readonly InstagramProfileRelationshipStore $profileRelationshipStore,
        private readonly TrackedPersonInstagramProfileListScanService $listScanService,
        private readonly TrackedPersonInstagramPostScanService $postScanService,
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
            $payload = $this->scraper->scrape(
                $username,
                $fullScan ? 'profile' : 'mini',
                $progress,
            );
            $extracted = $this->extractor->extract($payload);
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

            return [
                'profile' => $profile->fresh() ?: $profile,
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
}
