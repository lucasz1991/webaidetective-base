<?php

namespace App\Services\Social;

use App\Models\InstagramMediaAsset;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InstagramMediaAssetStore
{
    private ?bool $ready = null;

    public function findReusableByUrl(
        mixed $username,
        string $mediaRole,
        string $mediaType,
        ?string $sourceUrl,
    ): ?InstagramMediaAsset {
        if (! $this->isReady()) {
            return null;
        }

        $username = $this->normalizeUsername($username);
        $sourceUrlHash = $this->sourceUrlHash($sourceUrl);

        if ($username === null || $sourceUrlHash === null) {
            return null;
        }

        $asset = InstagramMediaAsset::query()
            ->where('instagram_username', $username)
            ->where('media_role', $this->normalizeRole($mediaRole))
            ->where('media_type', $this->normalizeMediaType($mediaType))
            ->where('source_url_hash', $sourceUrlHash)
            ->latest('last_seen_at')
            ->latest('id')
            ->first();

        return $this->usableAsset($asset);
    }

    public function findReusableByHash(
        mixed $username,
        string $mediaRole,
        string $mediaType,
        ?string $contentHash,
    ): ?InstagramMediaAsset {
        if (! $this->isReady()) {
            return null;
        }

        $username = $this->normalizeUsername($username);
        $contentHash = $this->normalizeContentHash($contentHash);

        if ($username === null || $contentHash === null) {
            return null;
        }

        $asset = InstagramMediaAsset::query()
            ->where('instagram_username', $username)
            ->where('media_role', $this->normalizeRole($mediaRole))
            ->where('media_type', $this->normalizeMediaType($mediaType))
            ->where('content_hash', $contentHash)
            ->latest('last_seen_at')
            ->latest('id')
            ->first();

        return $this->usableAsset($asset);
    }

    public function remember(
        mixed $username,
        string $mediaRole,
        string $mediaType,
        ?string $sourceUrl,
        ?string $contentHash,
        ?string $storagePath,
        ?string $mimeType = null,
        ?int $fileSize = null,
        array $metadata = [],
    ): ?InstagramMediaAsset {
        if (! $this->isReady()) {
            return null;
        }

        $username = $this->normalizeUsername($username);
        $contentHash = $this->normalizeContentHash($contentHash);
        $storagePath = is_scalar($storagePath) ? trim((string) $storagePath) : '';

        if ($username === null || $storagePath === '') {
            return null;
        }

        $sourceUrl = $this->normalizeUrl($sourceUrl);
        $sourceUrlHash = $this->sourceUrlHash($sourceUrl);
        $mediaRole = $this->normalizeRole($mediaRole);
        $mediaType = $this->normalizeMediaType($mediaType);
        $now = now('UTC');

        $asset = null;

        if ($sourceUrlHash !== null) {
            $asset = InstagramMediaAsset::query()
                ->where('instagram_username', $username)
                ->where('media_role', $mediaRole)
                ->where('source_url_hash', $sourceUrlHash)
                ->first();
        }

        if (! $asset && $contentHash !== null) {
            $asset = InstagramMediaAsset::query()
                ->where('instagram_username', $username)
                ->where('media_role', $mediaRole)
                ->where('media_type', $mediaType)
                ->where('content_hash', $contentHash)
                ->first();
        }

        $asset ??= new InstagramMediaAsset([
            'instagram_username' => $username,
            'media_role' => $mediaRole,
            'media_type' => $mediaType,
            'first_seen_at' => $now,
        ]);

        $asset->forceFill([
            'instagram_username' => $username,
            'media_role' => $mediaRole,
            'media_type' => $mediaType,
            'source_url' => $sourceUrl ?: $asset->source_url,
            'source_url_hash' => $sourceUrlHash ?: $asset->source_url_hash,
            'content_hash' => $contentHash ?: $asset->content_hash,
            'storage_path' => $storagePath,
            'mime_type' => $mimeType ?: $asset->mime_type,
            'file_size' => $fileSize ?: $asset->file_size,
            'metadata' => $metadata !== [] ? $metadata : $asset->metadata,
            'first_seen_at' => $asset->first_seen_at ?: $now,
            'last_seen_at' => $now,
        ])->save();

        return $asset;
    }

    public function normalizeUsername(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $username = Str::lower(trim((string) $value));
        $username = preg_replace('/^https?:\/\/(www\.)?instagram\.com\//i', '', $username) ?? $username;
        $username = trim(ltrim($username, '@'), "/ \t\n\r\0\x0B");
        $username = preg_replace('/[?#].*$/', '', $username) ?? $username;

        return $username !== '' && preg_match('/^[a-z0-9._]+$/', $username) ? $username : null;
    }

    public function sourceUrlHash(?string $sourceUrl): ?string
    {
        $sourceUrl = $this->normalizeUrl($sourceUrl);

        return $sourceUrl !== null ? hash('sha256', $sourceUrl) : null;
    }

    private function usableAsset(?InstagramMediaAsset $asset): ?InstagramMediaAsset
    {
        if (! $asset || blank($asset->storage_path) || ! Storage::disk('public')->exists($asset->storage_path)) {
            return null;
        }

        $asset->forceFill(['last_seen_at' => now('UTC')])->save();

        return $asset;
    }

    private function normalizeUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $url;
    }

    private function normalizeRole(string $mediaRole): string
    {
        $mediaRole = Str::of($mediaRole)->lower()->replaceMatches('/[^a-z0-9_-]+/', '_')->trim('_')->toString();

        return $mediaRole !== '' ? Str::limit($mediaRole, 40, '') : 'media';
    }

    private function normalizeMediaType(string $mediaType): string
    {
        return in_array($mediaType, ['image', 'video'], true) ? $mediaType : 'image';
    }

    private function normalizeContentHash(?string $contentHash): ?string
    {
        $contentHash = Str::lower(trim((string) $contentHash));

        return preg_match('/^[a-f0-9]{64}$/', $contentHash) ? $contentHash : null;
    }

    private function isReady(): bool
    {
        return $this->ready ??= Schema::hasTable('instagram_media_assets');
    }
}
