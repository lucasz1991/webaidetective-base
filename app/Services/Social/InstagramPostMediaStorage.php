<?php

namespace App\Services\Social;

use App\Models\InstagramPost;
use App\Models\InstagramPostMedia;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InstagramPostMediaStorage
{
    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    private const MAX_VIDEO_BYTES = 200 * 1024 * 1024;

    public function __construct(
        private readonly InstagramMediaAssetStore $mediaAssets,
    ) {}

    public function storeForPost(InstagramPost $post, array $mediaItems): void
    {
        $mediaItems = collect($mediaItems)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->sortBy(fn (array $item): int => max(0, (int) ($item['position'] ?? 0)))
            ->values();

        foreach ($mediaItems as $index => $item) {
            $position = max(0, (int) ($item['position'] ?? $index));
            $mediaType = ($item['media_type'] ?? null) === 'video' ? 'video' : 'image';
            $sourceUrl = $this->normalizeMediaUrl($item['source_url'] ?? null);
            $previewUrl = $this->normalizeMediaUrl($item['preview_url'] ?? null);
            $media = InstagramPostMedia::firstOrNew([
                'instagram_post_id' => $post->id,
                'position' => $position,
            ]);

            $media->forceFill([
                'media_type' => $mediaType,
                'source_url' => $sourceUrl,
                'preview_url' => $previewUrl,
                'width' => $this->nullablePositiveInteger($item['width'] ?? null),
                'height' => $this->nullablePositiveInteger($item['height'] ?? null),
                'duration_ms' => $this->durationMilliseconds($item['duration_seconds'] ?? null),
                'download_status' => 'pending',
                'download_error' => null,
            ])->save();

            $errors = [];

            if (! $this->storedFileExists($media->storage_path)) {
                $storedMedia = $this->download(
                    $sourceUrl,
                    $post,
                    $position,
                    $mediaType,
                    $mediaType,
                );

                if ($storedMedia['ok']) {
                    $media->forceFill([
                        'storage_path' => $storedMedia['storage_path'],
                        'mime_type' => $storedMedia['mime_type'],
                        'file_size' => $storedMedia['file_size'],
                        'content_hash' => $storedMedia['content_hash'],
                    ]);
                } else {
                    $errors[] = $storedMedia['error'];
                }
            }

            if ($mediaType === 'video' && ! $this->storedFileExists($media->preview_storage_path)) {
                $storedPreview = $this->download(
                    $previewUrl,
                    $post,
                    $position,
                    'image',
                    'preview',
                );

                if ($storedPreview['ok']) {
                    $media->preview_storage_path = $storedPreview['storage_path'];
                } else {
                    $errors[] = $storedPreview['error'];
                }
            }

            $hasMedia = $this->storedFileExists($media->storage_path);
            $hasRequiredPreview = $mediaType !== 'video'
                || $this->storedFileExists($media->preview_storage_path);

            $media->forceFill([
                'download_status' => $hasMedia && $hasRequiredPreview
                    ? 'stored'
                    : ($hasMedia ? 'partial' : 'failed'),
                'download_error' => $errors !== [] ? implode(' | ', array_filter($errors)) : null,
                'downloaded_at' => $hasMedia ? now('UTC') : null,
            ])->save();
        }

        $primaryMedia = $post->media()->orderBy('position')->first();

        $post->forceFill([
            'media_count' => $post->media()->count(),
            'thumbnail_path' => $primaryMedia?->preview_storage_path
                ?: ($primaryMedia?->media_type === 'image' ? $primaryMedia?->storage_path : $post->thumbnail_path),
        ])->save();
    }

    private function download(
        ?string $url,
        InstagramPost $post,
        int $position,
        string $expectedType,
        string $fileRole,
    ): array {
        if ($url === null) {
            return $this->failedResult('Keine gueltige Medien-URL vorhanden.');
        }

        $username = $this->mediaAssets->normalizeUsername($post->instagramProfile?->username)
            ?: 'profile-'.$post->instagram_profile_id;
        $assetRole = $fileRole === 'preview' ? 'post_preview' : 'post_media';
        $cachedByUrl = $this->mediaAssets->findReusableByUrl($username, $assetRole, $expectedType, $url);

        if ($cachedByUrl) {
            return [
                'ok' => true,
                'storage_path' => $cachedByUrl->storage_path,
                'mime_type' => $cachedByUrl->mime_type,
                'file_size' => $cachedByUrl->file_size,
                'content_hash' => $cachedByUrl->content_hash,
                'error' => null,
            ];
        }

        $temporaryDirectory = storage_path('app/tmp/instagram-post-media');
        File::ensureDirectoryExists($temporaryDirectory);
        $temporaryPath = $temporaryDirectory.DIRECTORY_SEPARATOR.Str::uuid().'.download';

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept' => $expectedType === 'video' ? 'video/*,*/*;q=0.8' : 'image/*,*/*;q=0.8',
                'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
                'Referer' => 'https://www.instagram.com/',
            ])
                ->timeout($expectedType === 'video' ? 180 : 30)
                ->retry(1, 500)
                ->withOptions(['sink' => $temporaryPath])
                ->get($url);
        } catch (\Throwable $exception) {
            File::delete($temporaryPath);
            $this->logDownloadFailure($post, $position, $fileRole, $exception->getMessage());

            return $this->failedResult('Download fehlgeschlagen: '.$exception->getMessage());
        }

        if (! $response->successful() || ! File::exists($temporaryPath)) {
            File::delete($temporaryPath);
            $error = 'HTTP '.($response->status() ?: 'unbekannt');
            $this->logDownloadFailure($post, $position, $fileRole, $error);

            return $this->failedResult('Download fehlgeschlagen: '.$error.'.');
        }

        $fileSize = File::size($temporaryPath);
        $maxBytes = $expectedType === 'video' ? self::MAX_VIDEO_BYTES : self::MAX_IMAGE_BYTES;

        if ($fileSize <= 0 || $fileSize > $maxBytes) {
            File::delete($temporaryPath);

            return $this->failedResult(sprintf(
                'Dateigroesse %s Bytes liegt ausserhalb des erlaubten Bereichs.',
                number_format($fileSize, 0, ',', '.'),
            ));
        }

        $mimeType = Str::lower(trim((string) $response->header('Content-Type')));

        if (! $this->contentTypeMatches($mimeType, $expectedType)) {
            File::delete($temporaryPath);

            return $this->failedResult('Unerwarteter Dateityp: '.($mimeType ?: 'unbekannt').'.');
        }

        $contentHash = hash_file('sha256', $temporaryPath);
        $extension = $this->extension($mimeType, $url, $expectedType);
        $cachedByHash = $this->mediaAssets->findReusableByHash($username, $assetRole, $expectedType, $contentHash);

        if ($cachedByHash) {
            File::delete($temporaryPath);

            $this->mediaAssets->remember(
                $username,
                $assetRole,
                $expectedType,
                $url,
                $contentHash,
                $cachedByHash->storage_path,
                $cachedByHash->mime_type ?: $mimeType,
                $cachedByHash->file_size ?: $fileSize,
                [
                    'shortcode' => $post->shortcode,
                    'position' => $position,
                    'file_role' => $fileRole,
                    'dedupe' => 'hash',
                ],
            );

            return [
                'ok' => true,
                'storage_path' => $cachedByHash->storage_path,
                'mime_type' => $cachedByHash->mime_type ?: ($mimeType ?: null),
                'file_size' => $cachedByHash->file_size ?: $fileSize,
                'content_hash' => $contentHash,
                'error' => null,
            ];
        }

        $relativePath = sprintf(
            'instagram-posts/%s/%s/%02d-%s-%s.%s',
            trim($username, '@/'),
            $post->shortcode,
            $position + 1,
            $fileRole,
            substr($contentHash, 0, 20),
            $extension,
        );

        if (! Storage::disk('public')->exists($relativePath)) {
            $stream = fopen($temporaryPath, 'rb');

            try {
                Storage::disk('public')->put($relativePath, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        File::delete($temporaryPath);

        $this->mediaAssets->remember(
            $username,
            $assetRole,
            $expectedType,
            $url,
            $contentHash,
            $relativePath,
            $mimeType ?: null,
            $fileSize,
            [
                'shortcode' => $post->shortcode,
                'position' => $position,
                'file_role' => $fileRole,
            ],
        );

        return [
            'ok' => true,
            'storage_path' => $relativePath,
            'mime_type' => $mimeType ?: null,
            'file_size' => $fileSize,
            'content_hash' => $contentHash,
            'error' => null,
        ];
    }

    private function normalizeMediaUrl(mixed $url): ?string
    {
        if (! is_scalar($url)) {
            return null;
        }

        $url = trim((string) $url);

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = Str::lower((string) parse_url($url, PHP_URL_SCHEME));
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        $allowedHost = Str::endsWith($host, [
            'instagram.com',
            'cdninstagram.com',
            'fbcdn.net',
        ]);

        return $scheme === 'https' && $allowedHost ? $url : null;
    }

    private function storedFileExists(?string $path): bool
    {
        return filled($path) && Storage::disk('public')->exists($path);
    }

    private function contentTypeMatches(string $mimeType, string $expectedType): bool
    {
        if ($mimeType === '' || Str::contains($mimeType, 'application/octet-stream')) {
            return true;
        }

        return Str::startsWith($mimeType, $expectedType.'/');
    }

    private function extension(string $mimeType, string $url, string $expectedType): string
    {
        return match (true) {
            Str::contains($mimeType, 'jpeg'), Str::contains($mimeType, 'jpg') => 'jpg',
            Str::contains($mimeType, 'png') => 'png',
            Str::contains($mimeType, 'webp') => 'webp',
            Str::contains($mimeType, 'gif') => 'gif',
            Str::contains($mimeType, 'avif') => 'avif',
            Str::contains($mimeType, 'quicktime') => 'mov',
            Str::contains($mimeType, 'webm') => 'webm',
            Str::contains($mimeType, 'mp4') => 'mp4',
            default => $this->extensionFromUrl($url, $expectedType),
        };
    }

    private function extensionFromUrl(string $url, string $expectedType): string
    {
        $extension = Str::lower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $allowed = $expectedType === 'video'
            ? ['mp4', 'mov', 'webm']
            : ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

        if (! in_array($extension, $allowed, true)) {
            return $expectedType === 'video' ? 'mp4' : 'jpg';
        }

        return $extension === 'jpeg' ? 'jpg' : $extension;
    }

    private function durationMilliseconds(mixed $seconds): ?int
    {
        return is_numeric($seconds) && (float) $seconds > 0
            ? (int) round((float) $seconds * 1000)
            : null;
    }

    private function nullablePositiveInteger(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function failedResult(string $error): array
    {
        return [
            'ok' => false,
            'storage_path' => null,
            'mime_type' => null,
            'file_size' => null,
            'content_hash' => null,
            'error' => $error,
        ];
    }

    private function logDownloadFailure(InstagramPost $post, int $position, string $fileRole, string $error): void
    {
        Log::warning('Instagram-Beitragsmedium konnte nicht lokal gespeichert werden.', [
            'instagram_post_id' => $post->id,
            'shortcode' => $post->shortcode,
            'position' => $position,
            'file_role' => $fileRole,
            'error' => $error,
        ]);
    }
}
