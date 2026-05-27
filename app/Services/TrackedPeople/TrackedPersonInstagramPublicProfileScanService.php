<?php

namespace App\Services\TrackedPeople;

use App\Exceptions\TrackedPersonInstagramScanCancelledException;
use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramInferredConnection;
use App\Models\TrackedPersonInstagramPublicProfileScan;
use App\Models\TrackedPersonPublicProfile;
use App\Services\Social\InstagramScraper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TrackedPersonInstagramPublicProfileScanService
{
    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly TrackedPersonInstagramScanCoordinator $scanCoordinator,
    ) {
    }

    private ?array $activeScanControl = null;

    public function scan(TrackedPerson $trackedPerson, ?callable $progress = null): Collection
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
            return $this->scanWithLock($trackedPerson, $targetUsername, $progress);
        } finally {
            $lock->release();
            $this->scanCoordinator->finish($trackedPerson->id, (int) $scanControl['generation']);
            $this->activeScanControl = null;
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
                    $this->withActiveScanControl([
                        'publicConnectionCandidates' => $candidates,
                    ]),
                );

                $createdScans->push($this->storeScan($trackedPerson, $publicProfile, $payload));
            } catch (TrackedPersonInstagramScanCancelledException $exception) {
                throw $exception;
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

                $existing = $candidates[$username] ?? [
                    'username' => $username,
                    'displayName' => $this->nullableTrim($item['displayName'] ?? null),
                    'profileUrl' => $this->nullableTrim($item['profileUrl'] ?? null) ?: 'https://www.instagram.com/'.$username.'/',
                    'sourceLists' => [],
                ];

                if (! in_array($sourceList, $existing['sourceLists'], true)) {
                    $existing['sourceLists'][] = $sourceList;
                }

                $candidates[$username] = $existing;
            }
        }

        return array_values($candidates);
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
    ): TrackedPersonInstagramPublicProfileScan {
        $this->assertActiveScanCurrent();

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

    private function reportProgress(?callable $progress, array $payload): void
    {
        $this->assertActiveScanCurrent();

        if (! $progress) {
            return;
        }

        $progress([
            'phase' => $payload['phase'] ?? 'public-connections',
            'percent' => max(0, min(100, (int) ($payload['percent'] ?? 0))),
            'message' => (string) ($payload['message'] ?? 'Public-Profile-Verbindungsscan laeuft.'),
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
}
