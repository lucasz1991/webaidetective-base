<?php

namespace App\Services\TrackedPeople;

use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramPublicProfileScan;
use App\Models\TrackedPersonPublicProfile;
use App\Services\Social\InstagramScraper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TrackedPersonInstagramPublicProfileScanService
{
    public function __construct(
        private readonly InstagramScraper $scraper,
    ) {
    }

    public function scan(TrackedPerson $trackedPerson, ?callable $progress = null): Collection
    {
        $targetUsername = $this->scraper->normalizeInstagramUsername($trackedPerson->instagram_username);

        if ($targetUsername === null) {
            throw new \RuntimeException('Fuer diese Person ist kein Instagram-Name hinterlegt.');
        }

        $lock = Cache::lock('tracked-person-instagram-public-profile-scan:'.$trackedPerson->id, 3600);

        if (! $lock->get()) {
            throw new \RuntimeException('Fuer diese Person laeuft bereits ein Public-Profile-Verbindungsscan.');
        }

        try {
            return $this->scanWithLock($trackedPerson, $targetUsername, $progress);
        } finally {
            $lock->release();
        }
    }

    private function scanWithLock(TrackedPerson $trackedPerson, string $targetUsername, ?callable $progress = null): Collection
    {
        $publicProfiles = $trackedPerson->publicProfiles()
            ->where('platform', 'instagram')
            ->where('is_public', true)
            ->whereNotNull('username')
            ->latest()
            ->get();

        if ($publicProfiles->isEmpty()) {
            throw new \RuntimeException('Es sind keine bekannten oeffentlichen Instagram-Profile hinterlegt.');
        }

        $this->reportProgress($progress, [
            'phase' => 'public-connections',
            'percent' => 1,
            'message' => 'Public-Profile-Verbindungsscan wird vorbereitet.',
        ]);

        $createdScans = collect();
        $total = max(1, $publicProfiles->count());

        foreach ($publicProfiles->values() as $index => $publicProfile) {
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

            $profileStart = (int) floor(($index / $total) * 100);
            $profileEnd = (int) floor((($index + 1) / $total) * 100);

            $this->reportProgress($progress, [
                'phase' => 'public-connections',
                'percent' => max(1, $profileStart),
                'message' => 'Verbindung mit @'.$publicUsername.' wird geprueft.',
            ]);

            try {
                $payload = $this->scraper->scanPublicProfileConnection(
                    $publicUsername,
                    $targetUsername,
                    fn (array $state) => $this->reportProgress($progress, [
                        'phase' => 'public-connections',
                        'percent' => $profileStart + (int) floor(((int) ($state['percent'] ?? 0) / 100) * max(1, $profileEnd - $profileStart)),
                        'message' => (string) ($state['message'] ?? 'Verbindung mit @'.$publicUsername.' wird geprueft.'),
                    ]),
                );

                $createdScans->push($this->storeScan($trackedPerson, $publicProfile, $payload));
            } catch (\Throwable $exception) {
                $createdScans->push($this->storeFailedScan(
                    $trackedPerson,
                    $publicProfile,
                    $targetUsername,
                    $publicUsername,
                    $exception->getMessage(),
                ));
            }
        }

        $this->reportProgress($progress, [
            'phase' => 'done',
            'percent' => 100,
            'message' => 'Public-Profile-Verbindungsscan abgeschlossen.',
        ]);

        return $createdScans->values();
    }

    private function storeScan(
        TrackedPerson $trackedPerson,
        TrackedPersonPublicProfile $publicProfile,
        array $payload,
    ): TrackedPersonInstagramPublicProfileScan {
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
        ) {
            $scan = TrackedPersonInstagramPublicProfileScan::create([
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
                'status_message' => (string) ($payload['statusMessage'] ?? 'Verbindungsscan abgeschlossen.'),
                'raw_payload' => $payload,
                'analyzed_at' => $analyzedAt,
            ]);

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
    ): TrackedPersonInstagramPublicProfileScan {
        return TrackedPersonInstagramPublicProfileScan::create([
            'tracked_person_id' => $trackedPerson->id,
            'public_profile_id' => $publicProfile->id,
            'user_id' => $trackedPerson->user_id,
            'target_username' => Str::lower($targetUsername),
            'public_username' => Str::lower($publicUsername),
            'relation_type' => 'unknown',
            'status_level' => 'error',
            'status_message' => 'Verbindungsscan fehlgeschlagen: '.$errorMessage,
            'raw_payload' => [
                'ok' => false,
                'error' => $errorMessage,
            ],
            'analyzed_at' => now('UTC'),
        ]);
    }

    private function normalizeRelationType(mixed $relationType): string
    {
        $relationType = Str::lower(trim((string) $relationType));

        return in_array($relationType, ['mutual', 'public_follows_target', 'target_follows_public', 'none', 'unknown'], true)
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

    private function nullableArray(mixed $value): ?array
    {
        return is_array($value) && $value !== [] ? $value : null;
    }

    private function reportProgress(?callable $progress, array $payload): void
    {
        if (! $progress) {
            return;
        }

        $progress([
            'phase' => $payload['phase'] ?? 'public-connections',
            'percent' => max(0, min(100, (int) ($payload['percent'] ?? 0))),
            'message' => (string) ($payload['message'] ?? 'Public-Profile-Verbindungsscan laeuft.'),
        ]);
    }
}
