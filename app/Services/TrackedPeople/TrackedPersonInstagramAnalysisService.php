<?php

namespace App\Services\TrackedPeople;

use App\Models\TrackedPerson;
use App\Models\TrackedPersonInstagramSnapshot;
use App\Services\Social\InstagramProfileDataExtractor;
use App\Services\Social\InstagramScraper;
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
    ) {
    }

    public function analyze(TrackedPerson $trackedPerson): TrackedPersonInstagramSnapshot
    {
        if (! $trackedPerson->instagram_username) {
            throw new \RuntimeException('Fuer diese Person ist kein Instagram-Name hinterlegt.');
        }

        [$payload, $extracted, $attemptInfo] = $this->scrapeUntilVisibleCounts($trackedPerson->instagram_username);
        $analyzedAt = now();
        $persistedWarnings = [];
        $previousSnapshot = $trackedPerson->instagramSnapshots()
            ->latest('analyzed_at')
            ->first();

        $snapshot = DB::transaction(function () use (
            $trackedPerson,
            $previousSnapshot,
            $payload,
            $extracted,
            $attemptInfo,
            $analyzedAt,
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
                'raw_payload' => $this->buildStoredPayload($payload, $extracted, [], $attemptInfo, null),
                'analyzed_at' => $payload['scrapedAt'] ?? $analyzedAt,
            ]);

            $storedMedia = $this->storeSnapshotMedia(
                $trackedPerson,
                $snapshot,
                $extracted['image_urls'] ?? [],
                $persistedWarnings,
                $previousSnapshot,
            );
            $profileMedia = collect($storedMedia)->firstWhere('is_profile_image', true);
            $profileImagePath = $profileMedia['storage_path'] ?? null;
            $profileImageHash = $profileMedia['content_hash'] ?? null;
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
                'last_instagram_status_message' => $snapshot->status_message,
                'last_instagram_analyzed_at' => $snapshot->analyzed_at,
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

            return $snapshot;
        });

        return $snapshot->fresh('media');
    }

    private function scrapeUntilVisibleCounts(string $username): array
    {
        $attempts = [];
        $lastPayload = [];
        $lastExtracted = [];

        for ($attempt = 1; $attempt <= self::MAX_VISIBLE_RETRY_ATTEMPTS; $attempt++) {
            $payload = $this->scraper->scrape($username);
            $extracted = $this->extractor->extract($payload);

            $attempts[] = [
                'attempt' => $attempt,
                'statusLevel' => $payload['statusLevel'] ?? 'unknown',
                'statusMessage' => $payload['statusMessage'] ?? null,
                'followersSource' => $extracted['count_sources']['followers'] ?? null,
                'followingSource' => $extracted['count_sources']['following'] ?? null,
                'postsSource' => $extracted['count_sources']['posts'] ?? null,
                'visibleCountsComplete' => (bool) ($extracted['visible_counts_complete'] ?? false),
            ];

            $lastPayload = $payload;
            $lastExtracted = $extracted;

            if ($extracted['visible_counts_complete'] ?? false) {
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

    private function resolveStatusMessage(array $payload, array $attemptInfo): string
    {
        $statusMessage = $payload['statusMessage'] ?? ($payload['error'] ?? 'Instagram-Scrape fehlgeschlagen.');

        if (! ($attemptInfo['visible_counts_complete'] ?? false)) {
            return $statusMessage.' Sichtbare Kennzahlen konnten nach '.($attemptInfo['used_attempt'] ?? 1).' Versuch(en) nicht stabil geladen werden.';
        }

        if (($attemptInfo['used_attempt'] ?? 1) > 1) {
            return $statusMessage.' Sichtbare Kennzahlen wurden erst nach '.($attemptInfo['used_attempt']).' Versuch(en) geladen.';
        }

        return $statusMessage;
    }

    private function buildStoredPayload(
        array $payload,
        array $extracted,
        array $detectedChanges,
        array $attemptInfo,
        ?string $profileImageHash,
    ): array {
        $existingWarnings = array_values(array_filter(array_merge(
            $payload['warnings'] ?? [],
            $extracted['count_warnings'] ?? [],
        )));

        $payload['warnings'] = array_values(array_unique($existingWarnings));
        $payload['extractedProfile'] = [
            'fullName' => $extracted['full_name'] ?? null,
            'biography' => $extracted['biography'] ?? null,
            'postsCount' => $extracted['posts_count'] ?? null,
            'followersCount' => $extracted['followers_count'] ?? null,
            'followingCount' => $extracted['following_count'] ?? null,
            'countSources' => $extracted['count_sources'] ?? [],
            'countWarnings' => $extracted['count_warnings'] ?? [],
            'visibleCountsComplete' => (bool) ($extracted['visible_counts_complete'] ?? false),
            'profileImageHash' => $profileImageHash,
            'detectedChanges' => $detectedChanges,
        ];
        $payload['analysisPolicy'] = [
            'counts' => 'visible-only',
            'retryAttempts' => $attemptInfo['attempts'] ?? [],
            'usedAttempt' => $attemptInfo['used_attempt'] ?? 1,
            'maxAttempts' => $attemptInfo['max_attempts'] ?? self::MAX_VISIBLE_RETRY_ATTEMPTS,
            'monitoringOnly' => 'public-visible-data',
        ];

        return $payload;
    }

    private function appendPersistedWarnings(array $payload, array $persistedWarnings): array
    {
        if ($persistedWarnings === []) {
            return $payload;
        }

        $payload['persistedWarnings'] = array_values(array_unique($persistedWarnings));

        return $payload;
    }

    private function detectSnapshotChanges(
        ?TrackedPersonInstagramSnapshot $previousSnapshot,
        array $extracted,
        ?string $profileImageHash,
    ): array {
        if (! $previousSnapshot) {
            return [];
        }

        $labels = [
            'full_name' => 'Name',
            'biography' => 'Bio',
            'posts_count' => 'Beitraege',
            'followers_count' => 'Follower',
            'following_count' => 'Gefolgt',
        ];
        $changes = [];

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

        return $changes;
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
            ])->timeout(45)->retry(2, 500)->get($imageUrl);
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
