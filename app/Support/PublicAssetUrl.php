<?php

namespace App\Support;

use App\Models\Setting;

class PublicAssetUrl
{
    private static array $settingUrlCache = [];

    public static function storage(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (self::isAbsoluteUrl($path)) {
            return $path;
        }

        $prefix = str_starts_with($path, 'storage/') ? '' : 'storage/';

        return rtrim(self::baseUrl(), '/').'/'.$prefix.ltrim($path, '/');
    }

    public static function fromStorageOrRemote(?string $storagePath, ?string $remoteUrl = null): ?string
    {
        return self::storage($storagePath) ?: self::normalizeUrl($remoteUrl);
    }

    public static function baseUrl(): string
    {
        $baseUrl = self::settingUrl('base_url')
            ?: self::settingUrl('app_url')
            ?: rtrim((string) config('app.url'), '/');

        return rtrim($baseUrl, '/');
    }

    private static function settingUrl(string $key): ?string
    {
        if (array_key_exists($key, self::$settingUrlCache)) {
            return self::$settingUrlCache[$key];
        }

        $value = Setting::query()
            ->where('type', 'base')
            ->where('key', $key)
            ->value('value');

        if (! is_string($value)) {
            return self::$settingUrlCache[$key] = null;
        }

        $value = trim($value);

        if ($value === '') {
            return self::$settingUrlCache[$key] = null;
        }

        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $decoded = json_decode($value, true);

            if (is_string($decoded) && trim($decoded) !== '') {
                $value = trim($decoded);
            }
        }

        return self::$settingUrlCache[$key] = ($value !== '' ? rtrim($value, '/') : null);
    }

    private static function normalizeUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        return $url !== '' ? $url : null;
    }

    private static function isAbsoluteUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }
}
