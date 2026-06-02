<?php

namespace App\Services\Scraper;

use App\Models\ScraperProfile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ScraperProfileDatabaseStore
{
    public function isAvailable(): bool
    {
        return Schema::hasTable('scraper_profiles');
    }

    public function importLegacyCollectionIfMissing(array $collection, ?string $storageRoot = null): void
    {
        if (! $this->isAvailable() || ScraperProfile::query()->where('platform', 'instagram')->exists()) {
            return;
        }

        $this->persistProfileCollection($collection, $storageRoot);
    }

    public function loadProfileCollection(?array $fallbackCollection = null): ?array
    {
        if (! $this->isAvailable()) {
            return $fallbackCollection;
        }

        $profiles = ScraperProfile::query()
            ->where('platform', 'instagram')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($profiles->isEmpty()) {
            return $fallbackCollection;
        }

        $activeProfileId = optional($profiles->firstWhere('is_primary', true))->profile_key
            ?: optional($profiles->firstWhere('is_active', true))->profile_key
            ?: $profiles->first()->profile_key;
        $activeProfileIds = $profiles
            ->where('is_active', true)
            ->pluck('profile_key')
            ->values()
            ->all();

        if ($activeProfileIds === []) {
            $activeProfileIds = [$activeProfileId];
        }

        return [
            'active_profile_id' => $activeProfileId,
            'active_profile_ids' => $activeProfileIds,
            'profiles' => $profiles
                ->map(fn (ScraperProfile $profile): array => $this->profileArray($profile))
                ->values()
                ->all(),
            'updated_at' => optional($profiles->sortByDesc('updated_at')->first()?->updated_at)->toIso8601String() ?: now()->toIso8601String(),
        ];
    }

    public function persistProfileCollection(array $collection, ?string $storageRoot = null): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        $profiles = array_values(array_filter(
            $collection['profiles'] ?? [],
            static fn ($profile): bool => is_array($profile) && trim((string) ($profile['id'] ?? '')) !== '',
        ));
        $activeProfileId = trim((string) ($collection['active_profile_id'] ?? ($profiles[0]['id'] ?? 'default')));
        $activeProfileIds = array_values(array_unique(array_map(
            static fn ($profileId): string => trim((string) $profileId),
            array_filter($collection['active_profile_ids'] ?? [], 'is_scalar'),
        )));
        $profileKeys = [];

        foreach ($profiles as $index => $profile) {
            $profileKey = trim((string) ($profile['id'] ?? ''));

            if ($profileKey === '') {
                continue;
            }

            $profileKeys[] = $profileKey;
            $record = ScraperProfile::withTrashed()->firstOrNew([
                'platform' => 'instagram',
                'profile_key' => $profileKey,
            ]);

            if ($record->exists && $record->trashed()) {
                $record->restore();
            }

            $record->forceFill([
                'profile_label' => $this->stringValue($profile['profile_label'] ?? 'instagram-default', 'instagram-default'),
                'browser_profile_path' => $this->nullableString($profile['browser_profile_path'] ?? null),
                'cookie_file_path' => $this->nullableString($profile['cookie_file_path'] ?? null),
                'persistent_profile_enabled' => (bool) ($profile['persistent_profile_enabled'] ?? true),
                'headless_enabled' => (bool) ($profile['headless_enabled'] ?? true),
                'auto_login_enabled' => (bool) ($profile['auto_login_enabled'] ?? false),
                'login_username' => $this->nullableString($profile['login_username'] ?? null),
                'login_password_encrypted' => $this->nullableString($profile['login_password_encrypted'] ?? null),
                'login_password_base_encrypted' => $this->nullableString($profile['login_password_base_encrypted'] ?? null),
                'navigation_timeout_seconds' => max(30, (int) ($profile['navigation_timeout_seconds'] ?? 120)),
                'post_login_wait_ms' => max(500, (int) ($profile['post_login_wait_ms'] ?? 2500)),
                'typing_delay_ms' => max(0, (int) ($profile['typing_delay_ms'] ?? 35)),
                'relationship_list_process_timeout_seconds' => max(60, (int) ($profile['relationship_list_process_timeout_seconds'] ?? 14400)),
                'relationship_list_max_scroll_rounds' => max(20, (int) ($profile['relationship_list_max_scroll_rounds'] ?? 100000)),
                'follower_list_max_items' => max(0, (int) ($profile['follower_list_max_items'] ?? 0)),
                'following_list_max_items' => max(0, (int) ($profile['following_list_max_items'] ?? 0)),
                'is_primary' => $profileKey === $activeProfileId,
                'is_active' => in_array($profileKey, $activeProfileIds, true),
                'sort_order' => $index,
                'metadata' => [
                    'legacy_imported_at' => $record->exists ? data_get($record->metadata, 'legacy_imported_at') : now()->toIso8601String(),
                ],
            ])->save();

            $this->syncCookiePayloadFromProfileFile($record, $profile, $storageRoot);
        }

        ScraperProfile::query()
            ->where('platform', 'instagram')
            ->whereNotIn('profile_key', $profileKeys)
            ->delete();
    }

    public function hydrateCookieFilesFromCollection(array $collection, ?string $storageRoot = null): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        foreach ($collection['profiles'] ?? [] as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $record = ScraperProfile::query()
                ->where('platform', 'instagram')
                ->where('profile_key', (string) ($profile['id'] ?? ''))
                ->first();

            if (! $record || ! is_string($record->cookie_payload) || trim($record->cookie_payload) === '') {
                continue;
            }

            $cookieFilePath = $this->resolveCookieFilePath($profile, $storageRoot);

            if (! $cookieFilePath) {
                continue;
            }

            File::ensureDirectoryExists(dirname($cookieFilePath));
            File::put($cookieFilePath, $record->cookie_payload);
        }
    }

    public function hydrateCookieFilesFromRuntimeConfig(array $runtimeConfig): void
    {
        $this->hydrateCookieFilesFromRuntimeProfiles($this->runtimeProfiles($runtimeConfig));
    }

    public function syncCookiePayloadsFromRuntimeConfigFile(?string $runtimeConfigPath): void
    {
        if (! $runtimeConfigPath || ! File::exists($runtimeConfigPath)) {
            return;
        }

        try {
            $runtimeConfig = json_decode(File::get($runtimeConfigPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }

        if (is_array($runtimeConfig)) {
            $this->syncCookiePayloadsFromRuntimeConfig($runtimeConfig);
        }
    }

    public function syncCookiePayloadsFromRuntimeConfig(array $runtimeConfig): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        foreach ($this->runtimeProfiles($runtimeConfig) as $runtimeProfile) {
            $profileKey = trim((string) ($runtimeProfile['profileId'] ?? ''));
            $cookieFilePath = trim((string) ($runtimeProfile['cookieFilePath'] ?? ''));

            if ($profileKey === '' || $cookieFilePath === '') {
                continue;
            }

            $record = ScraperProfile::query()
                ->where('platform', 'instagram')
                ->where('profile_key', $profileKey)
                ->first();

            if ($record) {
                $this->syncCookiePayloadFromFilePath($record, $cookieFilePath);
            }
        }
    }

    private function hydrateCookieFilesFromRuntimeProfiles(array $runtimeProfiles): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        foreach ($runtimeProfiles as $runtimeProfile) {
            $profileKey = trim((string) ($runtimeProfile['profileId'] ?? ''));
            $cookieFilePath = trim((string) ($runtimeProfile['cookieFilePath'] ?? ''));

            if ($profileKey === '' || $cookieFilePath === '') {
                continue;
            }

            $record = ScraperProfile::query()
                ->where('platform', 'instagram')
                ->where('profile_key', $profileKey)
                ->first();

            if (! $record || ! is_string($record->cookie_payload) || trim($record->cookie_payload) === '') {
                continue;
            }

            File::ensureDirectoryExists(dirname($cookieFilePath));
            File::put($cookieFilePath, $record->cookie_payload);
        }
    }

    private function syncCookiePayloadFromProfileFile(ScraperProfile $record, array $profile, ?string $storageRoot = null): void
    {
        $cookieFilePath = $this->resolveCookieFilePath($profile, $storageRoot);

        if ($cookieFilePath) {
            $this->syncCookiePayloadFromFilePath($record, $cookieFilePath);
        }
    }

    private function syncCookiePayloadFromFilePath(ScraperProfile $record, string $cookieFilePath): void
    {
        if (! File::exists($cookieFilePath)) {
            return;
        }

        $payload = trim(File::get($cookieFilePath));

        if ($payload === '') {
            return;
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }

        $cookies = $this->extractCookieArray($decoded);
        $normalizedPayload = json_encode($cookies, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($normalizedPayload) || $normalizedPayload === '') {
            return;
        }

        $record->forceFill([
            'cookie_payload' => $normalizedPayload,
            'cookie_payload_hash' => hash('sha256', $normalizedPayload),
            'cookie_count' => count($cookies),
            'session_cookie_present' => collect($cookies)->contains(fn ($cookie): bool => is_array($cookie) && ($cookie['name'] ?? null) === 'sessionid'),
            'cookies_synced_at' => now('UTC'),
        ])->save();
    }

    private function profileArray(ScraperProfile $profile): array
    {
        return [
            'id' => $profile->profile_key,
            'profile_label' => $profile->profile_label,
            'persistent_profile_enabled' => (bool) $profile->persistent_profile_enabled,
            'browser_profile_path' => $profile->browser_profile_path ?: 'browser-profiles/instagram/default',
            'cookie_file_path' => $profile->cookie_file_path ?: 'cookies/instagram-cookies.json',
            'headless_enabled' => (bool) $profile->headless_enabled,
            'auto_login_enabled' => (bool) $profile->auto_login_enabled,
            'login_username' => (string) $profile->login_username,
            'login_password_encrypted' => $profile->login_password_encrypted,
            'login_password_base_encrypted' => $profile->login_password_base_encrypted,
            'navigation_timeout_seconds' => (int) $profile->navigation_timeout_seconds,
            'post_login_wait_ms' => (int) $profile->post_login_wait_ms,
            'typing_delay_ms' => (int) $profile->typing_delay_ms,
            'relationship_list_process_timeout_seconds' => (int) $profile->relationship_list_process_timeout_seconds,
            'relationship_list_max_scroll_rounds' => (int) $profile->relationship_list_max_scroll_rounds,
            'follower_list_max_items' => (int) $profile->follower_list_max_items,
            'following_list_max_items' => (int) $profile->following_list_max_items,
            'updated_at' => optional($profile->updated_at)->toIso8601String() ?: now()->toIso8601String(),
        ];
    }

    private function resolveCookieFilePath(array $profile, ?string $storageRoot = null): ?string
    {
        $cookieFilePath = trim((string) ($profile['cookie_file_path'] ?? ''));

        if ($cookieFilePath === '') {
            return null;
        }

        if ($this->isAbsolutePath($cookieFilePath)) {
            return $cookieFilePath;
        }

        $storageRoot = $storageRoot ?: storage_path('app');

        return rtrim($storageRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $cookieFilePath), DIRECTORY_SEPARATOR);
    }

    private function extractCookieArray(mixed $decoded): array
    {
        $cookies = is_array($decoded) && array_is_list($decoded)
            ? $decoded
            : (is_array($decoded) && is_array($decoded['cookies'] ?? null) ? $decoded['cookies'] : []);

        return array_values(array_filter($cookies, static fn ($cookie): bool => is_array($cookie) && filled($cookie['name'] ?? null)));
    }

    private function runtimeProfiles(array $runtimeConfig): array
    {
        $profiles = [$runtimeConfig];

        if (is_array($runtimeConfig['accountPool'] ?? null)) {
            foreach ($runtimeConfig['accountPool'] as $account) {
                if (is_array($account)) {
                    $profiles[] = $account;
                }
            }
        }

        $seen = [];

        return array_values(array_filter($profiles, function (array $profile) use (&$seen): bool {
            $key = trim((string) ($profile['profileId'] ?? ''));

            if ($key === '' || isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;

            return true;
        }));
    }

    private function stringValue(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : $fallback;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:\\\\/', $path) === 1
            || preg_match('/^[A-Za-z]:\//', $path) === 1;
    }
}
