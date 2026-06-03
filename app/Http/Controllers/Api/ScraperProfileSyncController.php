<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScraperProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ScraperProfileSyncController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorizeRequest($request);

        $validated = $request->validate([
            'active_profile_id' => ['nullable', 'string', 'max:255'],
            'active_profile_ids' => ['nullable', 'array'],
            'active_profile_ids.*' => ['string', 'max:255'],
            'replace' => ['nullable', 'boolean'],
            'profiles' => ['required', 'array', 'min:1'],
            'profiles.*.profile_key' => ['required', 'string', 'max:255'],
            'profiles.*.profile_label' => ['required', 'string', 'max:255'],
            'profiles.*.social_accounts' => ['nullable', 'array'],
            'profiles.*.browser_profile_path' => ['nullable', 'string', 'max:255'],
            'profiles.*.cookie_file_path' => ['nullable', 'string', 'max:255'],
            'profiles.*.persistent_profile_enabled' => ['nullable', 'boolean'],
            'profiles.*.headless_enabled' => ['nullable', 'boolean'],
            'profiles.*.auto_login_enabled' => ['nullable', 'boolean'],
            'profiles.*.login_username' => ['nullable', 'string', 'max:255'],
            'profiles.*.login_password_base_encrypted' => ['nullable', 'string'],
            'profiles.*.navigation_timeout_seconds' => ['nullable', 'integer', 'min:30', 'max:300'],
            'profiles.*.post_login_wait_ms' => ['nullable', 'integer', 'min:500', 'max:15000'],
            'profiles.*.typing_delay_ms' => ['nullable', 'integer', 'min:0', 'max:500'],
            'profiles.*.relationship_list_process_timeout_seconds' => ['nullable', 'integer', 'min:60', 'max:21600'],
            'profiles.*.relationship_list_max_scroll_rounds' => ['nullable', 'integer', 'min:20', 'max:1000000'],
            'profiles.*.follower_list_max_items' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'profiles.*.following_list_max_items' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'profiles.*.is_active' => ['nullable', 'boolean'],
            'profiles.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'profiles.*.cookie_payload' => ['nullable', 'string'],
            'profiles.*.cookie_payload_hash' => ['nullable', 'string', 'max:64'],
            'profiles.*.cookie_count' => ['nullable', 'integer', 'min:0'],
            'profiles.*.session_cookie_present' => ['nullable', 'boolean'],
            'profiles.*.cookies_synced_at' => ['nullable', 'date'],
        ]);

        $activeProfileId = trim((string) ($validated['active_profile_id'] ?? ''));
        $activeProfileIds = collect($validated['active_profile_ids'] ?? [])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();
        $replace = (bool) ($validated['replace'] ?? true);
        $profileKeys = [];

        DB::transaction(function () use ($validated, $activeProfileId, $activeProfileIds, $replace, &$profileKeys): void {
            foreach ($validated['profiles'] as $index => $profile) {
                $profileKey = trim((string) $profile['profile_key']);
                $profileKeys[] = $profileKey;

                $record = ScraperProfile::withTrashed()->firstOrNew([
                    'platform' => 'instagram',
                    'profile_key' => $profileKey,
                ]);

                if ($record->exists && $record->trashed()) {
                    $record->restore();
                }

                $metadata = is_array($record->metadata) ? $record->metadata : [];
                $cookiePayload = $this->nullableString($profile['cookie_payload'] ?? null);
                $cookiePayloadHash = $this->nullableString($profile['cookie_payload_hash'] ?? null);

                if ($cookiePayload !== null && $cookiePayloadHash === null) {
                    $cookiePayloadHash = hash('sha256', $cookiePayload);
                }

                $record->forceFill([
                    'profile_label' => trim((string) $profile['profile_label']),
                    'social_accounts' => is_array($profile['social_accounts'] ?? null) ? $profile['social_accounts'] : null,
                    'browser_profile_path' => $this->nullableString($profile['browser_profile_path'] ?? null),
                    'cookie_file_path' => $this->nullableString($profile['cookie_file_path'] ?? null),
                    'persistent_profile_enabled' => (bool) ($profile['persistent_profile_enabled'] ?? true),
                    'headless_enabled' => (bool) ($profile['headless_enabled'] ?? true),
                    'auto_login_enabled' => (bool) ($profile['auto_login_enabled'] ?? false),
                    'login_username' => $this->nullableString($profile['login_username'] ?? null),
                    'login_password_encrypted' => null,
                    'login_password_base_encrypted' => $this->nullableString($profile['login_password_base_encrypted'] ?? null),
                    'navigation_timeout_seconds' => max(30, (int) ($profile['navigation_timeout_seconds'] ?? 120)),
                    'post_login_wait_ms' => max(500, (int) ($profile['post_login_wait_ms'] ?? 2500)),
                    'typing_delay_ms' => max(0, (int) ($profile['typing_delay_ms'] ?? 35)),
                    'relationship_list_process_timeout_seconds' => max(60, (int) ($profile['relationship_list_process_timeout_seconds'] ?? 14400)),
                    'relationship_list_max_scroll_rounds' => max(20, (int) ($profile['relationship_list_max_scroll_rounds'] ?? 100000)),
                    'follower_list_max_items' => max(0, (int) ($profile['follower_list_max_items'] ?? 0)),
                    'following_list_max_items' => max(0, (int) ($profile['following_list_max_items'] ?? 0)),
                    'is_primary' => $profileKey === $activeProfileId,
                    'is_active' => in_array($profileKey, $activeProfileIds, true) || (bool) ($profile['is_active'] ?? false),
                    'sort_order' => (int) ($profile['sort_order'] ?? $index),
                    'cookie_payload' => $cookiePayload,
                    'cookie_payload_hash' => $cookiePayloadHash,
                    'cookie_count' => max(0, (int) ($profile['cookie_count'] ?? 0)),
                    'session_cookie_present' => (bool) ($profile['session_cookie_present'] ?? false),
                    'cookies_synced_at' => $this->nullableDateTime($profile['cookies_synced_at'] ?? null),
                    'metadata' => [
                        ...$metadata,
                        'factory_sync' => [
                            'synced_at' => now('UTC')->toIso8601String(),
                            'source' => 'local-scraper-factory-api',
                        ],
                    ],
                ])->save();
            }

            if ($replace) {
                ScraperProfile::query()
                    ->where('platform', 'instagram')
                    ->whereNotIn('profile_key', $profileKeys)
                    ->delete();
            }
        });

        return response()->json([
            'ok' => true,
            'synced' => count($profileKeys),
            'profile_keys' => $profileKeys,
        ]);
    }

    private function authorizeRequest(Request $request): void
    {
        $configuredToken = trim((string) config('services.scraper_profile_sync.token'));
        $fallbackSettings = Setting::getValue('services', 'scraper_profile_sync');
        $fallbackToken = is_array($fallbackSettings) ? trim((string) ($fallbackSettings['token'] ?? '')) : '';
        $configuredToken = $configuredToken !== '' ? $configuredToken : $fallbackToken;

        abort_if($configuredToken === '', 503, 'Scraper profile sync is not configured.');
        abort_if(! hash_equals($configuredToken, (string) $request->bearerToken()), 403, 'Invalid scraper profile sync token.');
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function nullableDateTime(mixed $value): ?Carbon
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
