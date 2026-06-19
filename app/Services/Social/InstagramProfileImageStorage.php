<?php

namespace App\Services\Social;

use App\Models\InstagramProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InstagramProfileImageStorage
{
    private const MAX_IMAGE_BYTES = 5242880;

    public function __construct(
        private readonly InstagramMediaAssetStore $mediaAssets,
    ) {}

    public function storeFromUrl(InstagramProfile $profile, ?string $imageUrl): ?string
    {
        $imageUrl = $this->normalizeImageUrl($imageUrl);
        $username = $this->mediaAssets->normalizeUsername($profile->username);

        if ($imageUrl === null || $username === null) {
            return null;
        }

        $cachedByUrl = $this->mediaAssets->findReusableByUrl($username, 'profile_image', 'image', $imageUrl);

        if ($cachedByUrl) {
            $profile->forceFill([
                'profile_image_path' => $cachedByUrl->storage_path,
                'profile_image_hash' => $cachedByUrl->content_hash ?: $profile->profile_image_hash,
            ])->save();

            return $cachedByUrl->storage_path;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
                'Referer' => 'https://www.instagram.com/',
            ])->timeout(15)->retry(1, 350)->get($imageUrl);
        } catch (\Throwable $exception) {
            Log::warning('Instagram-Profilbild konnte nicht lokal gespeichert werden.', [
                'instagram_profile_id' => $profile->id,
                'username' => $profile->username,
                'image_url' => $imageUrl,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful() || $response->body() === '') {
            Log::warning('Instagram-Profilbild-Download fehlgeschlagen.', [
                'instagram_profile_id' => $profile->id,
                'username' => $profile->username,
                'image_url' => $imageUrl,
                'status' => $response->status(),
            ]);

            return null;
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_IMAGE_BYTES) {
            Log::warning('Instagram-Profilbild wurde wegen Dateigroesse nicht gespeichert.', [
                'instagram_profile_id' => $profile->id,
                'username' => $profile->username,
                'image_url' => $imageUrl,
                'bytes' => strlen($body),
            ]);

            return null;
        }

        $contentHash = hash('sha256', $body);

        if (
            $profile->profile_image_hash === $contentHash
            && filled($profile->profile_image_path)
            && Storage::disk('public')->exists($profile->profile_image_path)
        ) {
            $this->mediaAssets->remember(
                $username,
                'profile_image',
                'image',
                $imageUrl,
                $contentHash,
                $profile->profile_image_path,
                $response->header('Content-Type'),
                strlen($body),
            );

            return $profile->profile_image_path;
        }

        $cachedByHash = $this->mediaAssets->findReusableByHash($username, 'profile_image', 'image', $contentHash);

        if ($cachedByHash) {
            $this->mediaAssets->remember(
                $username,
                'profile_image',
                'image',
                $imageUrl,
                $contentHash,
                $cachedByHash->storage_path,
                $cachedByHash->mime_type ?: $response->header('Content-Type'),
                $cachedByHash->file_size ?: strlen($body),
            );

            $profile->forceFill([
                'profile_image_path' => $cachedByHash->storage_path,
                'profile_image_hash' => $contentHash,
            ])->save();

            return $cachedByHash->storage_path;
        }

        $extension = $this->guessExtension($response->header('Content-Type'), $imageUrl);
        $relativePath = 'instagram-profiles/'.$username.'/profile-'.substr($contentHash, 0, 20).'.'.$extension;

        if (! Storage::disk('public')->exists($relativePath)) {
            Storage::disk('public')->put($relativePath, $body);
        }

        $this->mediaAssets->remember(
            $username,
            'profile_image',
            'image',
            $imageUrl,
            $contentHash,
            $relativePath,
            $response->header('Content-Type'),
            strlen($body),
        );

        $profile->forceFill([
            'profile_image_path' => $relativePath,
            'profile_image_hash' => $contentHash,
        ])->save();

        return $relativePath;
    }

    private function normalizeImageUrl(?string $imageUrl): ?string
    {
        $imageUrl = trim((string) $imageUrl);

        if ($imageUrl === '' || ! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = Str::lower((string) parse_url($imageUrl, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $imageUrl : null;
    }

    private function guessExtension(?string $contentType, string $imageUrl): string
    {
        $contentType = Str::lower((string) $contentType);

        return match (true) {
            Str::contains($contentType, 'png') => 'png',
            Str::contains($contentType, 'webp') => 'webp',
            Str::contains($contentType, 'gif') => 'gif',
            Str::contains($contentType, 'avif') => 'avif',
            Str::contains($contentType, ['jpeg', 'jpg']) => 'jpg',
            default => $this->guessExtensionFromUrl($imageUrl),
        };
    }

    private function guessExtensionFromUrl(string $imageUrl): string
    {
        $path = parse_url($imageUrl, PHP_URL_PATH);
        $extension = Str::lower((string) pathinfo((string) $path, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'], true)
            ? ($extension === 'jpeg' ? 'jpg' : $extension)
            : 'jpg';
    }
}
