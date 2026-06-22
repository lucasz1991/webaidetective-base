<?php

namespace App\Services\Social;

use App\Models\InstagramStoryItem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InstagramStoryMediaStorage
{
    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    private const MAX_VIDEO_BYTES = 200 * 1024 * 1024;

    public function __construct(
        private readonly InstagramMediaAssetStore $mediaAssets,
    ) {}

    public function store(InstagramStoryItem $item): void
    {
        $errors = [];
        $mediaType = $item->media_type === 'video' ? 'video' : 'image';
        $main = $this->download($item, $item->source_url, $mediaType, 'media');

        if ($main['ok']) {
            $item->forceFill([
                'storage_path' => $main['storage_path'],
                'mime_type' => $main['mime_type'],
                'file_size' => $main['file_size'],
                'content_hash' => $main['content_hash'],
            ]);
        } else {
            $errors[] = $main['error'];
        }

        if ($mediaType === 'video' && filled($item->preview_url)) {
            $preview = $this->download($item, $item->preview_url, 'image', 'preview');

            if ($preview['ok']) {
                $item->preview_storage_path = $preview['storage_path'];
            } else {
                $errors[] = $preview['error'];
            }
        }

        $hasStoredMedia = filled($item->storage_path) && Storage::disk('public')->exists($item->storage_path);
        $item->forceFill([
            'download_status' => $hasStoredMedia ? ($errors === [] ? 'stored' : 'partial') : 'failed',
            'download_error' => $errors !== [] ? implode(' | ', array_filter($errors)) : null,
            'downloaded_at' => $hasStoredMedia ? now('UTC') : null,
        ])->save();
    }

    private function download(
        InstagramStoryItem $item,
        mixed $rawUrl,
        string $expectedType,
        string $fileRole,
    ): array {
        $url = $this->normalizeMediaUrl($rawUrl);

        if ($url === null) {
            return $this->failedResult('Keine gueltige Medien-URL vorhanden.');
        }

        $username = $this->mediaAssets->normalizeUsername($item->instagramProfile?->username)
            ?: 'profile-'.$item->instagram_profile_id;
        $assetRole = $item->source_type === 'highlights'
            ? 'highlight_'.$fileRole
            : 'story_'.$fileRole;
        $cached = $this->mediaAssets->findReusableByUrl($username, $assetRole, $expectedType, $url);

        if ($cached) {
            return [
                'ok' => true,
                'storage_path' => $cached->storage_path,
                'mime_type' => $cached->mime_type,
                'file_size' => $cached->file_size,
                'content_hash' => $cached->content_hash,
                'error' => null,
            ];
        }

        $temporaryDirectory = storage_path('app/tmp/instagram-story-media');
        File::ensureDirectoryExists($temporaryDirectory);
        $temporaryPath = $temporaryDirectory.DIRECTORY_SEPARATOR.Str::uuid().'.download';

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36',
                'Accept' => $expectedType === 'video' ? 'video/*,*/*;q=0.8' : 'image/*,*/*;q=0.8',
                'Referer' => 'https://www.instagram.com/',
            ])
                ->timeout($expectedType === 'video' ? 180 : 30)
                ->retry(1, 500)
                ->withOptions(['sink' => $temporaryPath])
                ->get($url);
        } catch (\Throwable $exception) {
            File::delete($temporaryPath);

            return $this->failedResult('Download fehlgeschlagen: '.$exception->getMessage());
        }

        if (! $response->successful() || ! File::exists($temporaryPath)) {
            File::delete($temporaryPath);

            return $this->failedResult('Download fehlgeschlagen: HTTP '.($response->status() ?: 'unbekannt').'.');
        }

        $fileSize = File::size($temporaryPath);
        $maxBytes = $expectedType === 'video' ? self::MAX_VIDEO_BYTES : self::MAX_IMAGE_BYTES;

        if ($fileSize <= 0 || $fileSize > $maxBytes) {
            File::delete($temporaryPath);

            return $this->failedResult('Ungueltige Dateigroesse.');
        }

        $mimeType = Str::lower(trim((string) $response->header('Content-Type')));

        if ($mimeType !== '' && ! Str::contains($mimeType, 'application/octet-stream') && ! Str::startsWith($mimeType, $expectedType.'/')) {
            File::delete($temporaryPath);

            return $this->failedResult('Unerwarteter Dateityp: '.$mimeType.'.');
        }

        $contentHash = hash_file('sha256', $temporaryPath);
        $cached = $this->mediaAssets->findReusableByHash($username, $assetRole, $expectedType, $contentHash);

        if ($cached) {
            File::delete($temporaryPath);
            $this->mediaAssets->remember(
                $username,
                $assetRole,
                $expectedType,
                $url,
                $contentHash,
                $cached->storage_path,
                $cached->mime_type ?: $mimeType,
                $cached->file_size ?: $fileSize,
                ['story_item_id' => $item->id, 'dedupe' => 'hash'],
            );

            return [
                'ok' => true,
                'storage_path' => $cached->storage_path,
                'mime_type' => $cached->mime_type ?: $mimeType,
                'file_size' => $cached->file_size ?: $fileSize,
                'content_hash' => $contentHash,
                'error' => null,
            ];
        }

        $extension = $this->extension($mimeType, $url, $expectedType);
        $relativePath = sprintf(
            'instagram-%s/%s/%d/%04d-%s-%s.%s',
            $item->source_type === 'highlights' ? 'highlights' : 'stories',
            trim($username, '@/'),
            $item->instagram_story_scan_id,
            $item->position + 1,
            $fileRole,
            substr($contentHash, 0, 16),
            $extension,
        );
        $stream = fopen($temporaryPath, 'rb');

        try {
            Storage::disk('public')->put($relativePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            File::delete($temporaryPath);
        }

        $this->mediaAssets->remember(
            $username,
            $assetRole,
            $expectedType,
            $url,
            $contentHash,
            $relativePath,
            $mimeType ?: null,
            $fileSize,
            ['story_item_id' => $item->id, 'source_type' => $item->source_type],
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

    private function normalizeMediaUrl(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $url = trim((string) $value);
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        return filter_var($url, FILTER_VALIDATE_URL)
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && Str::endsWith($host, ['instagram.com', 'cdninstagram.com', 'fbcdn.net'])
                ? $url
                : null;
    }

    private function extension(string $mimeType, string $url, string $expectedType): string
    {
        return match (true) {
            Str::contains($mimeType, ['jpeg', 'jpg']) => 'jpg',
            Str::contains($mimeType, 'png') => 'png',
            Str::contains($mimeType, 'webp') => 'webp',
            Str::contains($mimeType, 'avif') => 'avif',
            Str::contains($mimeType, 'webm') => 'webm',
            Str::contains($mimeType, 'quicktime') => 'mov',
            Str::contains($mimeType, 'mp4') => 'mp4',
            default => $expectedType === 'video' ? 'mp4' : 'jpg',
        };
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
}
