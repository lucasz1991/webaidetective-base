<?php

namespace App\Models;

use App\Services\TrackedPeople\TrackedPersonInstagramAnalysisService;
use App\Support\PublicAssetUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class TrackedPerson extends Model
{
    use HasFactory;

    public const INSTAGRAM_SCAN_PREFERENCE_DEFAULTS = [
        'monitoring_scan_mode' => 'mini',
        'auto_scan_followers' => true,
        'auto_scan_following' => true,
        'auto_scan_posts' => true,
        'auto_scan_suggestions' => true,
        'auto_scan_on_changes' => true,
        'auto_scan_on_interval' => false,
        'auto_scan_min_interval_minutes' => 60,
        'auto_scan_count_change_threshold' => 1,
    ];

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'alias',
        'date_of_birth',
        'city',
        'country',
        'notes',
        'instagram_username',
        'current_instagram_profile_id',
        'tiktok_username',
        'facebook_username',
        'x_username',
        'youtube_username',
        'snapchat_username',
        'notification_delivery_type',
        'notify_social_changes',
        'notify_instagram_changes',
        'notify_tiktok_changes',
        'notify_facebook_changes',
        'notify_x_changes',
        'notify_youtube_changes',
        'notify_snapchat_changes',
        'monitoring_enabled',
        'monitoring_interval_minutes',
        'instagram_scan_preferences',
        'is_primary',
        'profile_image_path',
        'profile_image_hash',
        'instagram_profile_image_path',
        'instagram_profile_image_hash',
        'instagram_followers_count',
        'instagram_following_count',
        'instagram_posts_count',
        'last_instagram_status_level',
        'last_instagram_status_message',
        'last_instagram_analyzed_at',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'last_instagram_analyzed_at' => 'datetime',
        'notify_social_changes' => 'boolean',
        'notify_instagram_changes' => 'boolean',
        'notify_tiktok_changes' => 'boolean',
        'notify_facebook_changes' => 'boolean',
        'notify_x_changes' => 'boolean',
        'notify_youtube_changes' => 'boolean',
        'notify_snapchat_changes' => 'boolean',
        'monitoring_enabled' => 'boolean',
        'monitoring_interval_minutes' => 'integer',
        'instagram_scan_preferences' => 'array',
        'is_primary' => 'boolean',
    ];

    protected $appends = [
        'display_name',
        'profile_image_url',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function knownFacts(): HasMany
    {
        return $this->hasMany(TrackedPersonKnownFact::class);
    }

    public function publicProfiles(): HasMany
    {
        return $this->hasMany(TrackedPersonPublicProfile::class);
    }

    public function currentInstagramProfile(): BelongsTo
    {
        return $this->belongsTo(InstagramProfile::class, 'current_instagram_profile_id');
    }

    public function instagramProfileLinks(): HasMany
    {
        return $this->hasMany(TrackedPersonInstagramProfileLink::class);
    }

    public function markInstagramScanTerminal(string $statusLevel, string $statusMessage): void
    {
        $statusLevel = in_array($statusLevel, ['success', 'error', 'cancelled'], true)
            ? $statusLevel
            : 'error';
        $timestamp = now('UTC');

        $this->forceFill([
            'last_instagram_status_level' => $statusLevel,
            'last_instagram_status_message' => $statusMessage,
            'last_instagram_analyzed_at' => $timestamp,
        ])->save();

        $progressSnapshot = $this->instagramSnapshots()->latest('id')->first();

        if (
            ! $progressSnapshot
            || ! (bool) data_get($progressSnapshot->raw_payload, 'analysisPolicy.progressSnapshot', false)
        ) {
            return;
        }

        $payload = is_array($progressSnapshot->raw_payload) ? $progressSnapshot->raw_payload : [];
        $payload['statusLevel'] = $statusLevel;
        $payload['statusMessage'] = $statusMessage;
        $payload['analysisPolicy'] = [
            ...(is_array($payload['analysisPolicy'] ?? null) ? $payload['analysisPolicy'] : []),
            'progressSnapshot' => false,
            'terminalStatus' => $statusLevel,
            'terminatedAt' => $timestamp->toIso8601String(),
        ];

        $progressSnapshot->forceFill([
            'status_level' => $statusLevel,
            'status_message' => $statusMessage,
            'raw_payload' => $payload,
            'analyzed_at' => $timestamp,
        ])->save();
    }

    public function instagramPublicProfileScans(): HasMany
    {
        return $this->hasMany(TrackedPersonInstagramPublicProfileScan::class);
    }

    public function instagramSuggestionScans(): HasMany
    {
        return $this->hasMany(TrackedPersonInstagramSuggestionScan::class);
    }

    public function instagramPostScans(): HasMany
    {
        return $this->hasMany(InstagramPostScan::class);
    }

    public function instagramStoryScans(): HasMany
    {
        return $this->hasMany(InstagramStoryScan::class);
    }

    public function instagramInferredConnections(): HasMany
    {
        return $this->hasMany(TrackedPersonInstagramInferredConnection::class);
    }

    public function instagramSnapshots(): HasMany
    {
        return $this->hasMany(TrackedPersonInstagramSnapshot::class);
    }

    public function latestInstagramSnapshot(): HasOne
    {
        return $this->hasOne(TrackedPersonInstagramSnapshot::class)->latestOfMany('analyzed_at');
    }

    public function latestChangedInstagramSnapshot(): HasOne
    {
        return $this->hasOne(TrackedPersonInstagramSnapshot::class)
            ->where('has_changes', true)
            ->latestOfMany('analyzed_at');
    }

    public function setInstagramUsernameAttribute($value): void
    {
        $this->attributes['instagram_username'] = $this->normalizeSocialUsername($value);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim(collect([$this->first_name, $this->last_name])->implode(' '))
            ?: ($this->alias ?: 'Unbenannte Person');
    }

    public function getProfileImageUrlAttribute(): ?string
    {
        $instagramProfile = $this->resolvedCurrentInstagramProfile();

        if ($instagramProfile) {
            return $instagramProfile->profile_image_storage_url;
        }

        return PublicAssetUrl::storage(
            $this->getRawOriginal('instagram_profile_image_path')
                ?: $this->getRawOriginal('profile_image_path'),
        );
    }

    public function getInstagramProfileImagePathAttribute($value): ?string
    {
        $instagramProfile = $this->resolvedCurrentInstagramProfile();

        return $instagramProfile
            ? $this->nullableString($instagramProfile->profile_image_path)
            : $this->nullableString($value);
    }

    public function getInstagramProfileImageHashAttribute($value): ?string
    {
        $instagramProfile = $this->resolvedCurrentInstagramProfile();

        return $instagramProfile
            ? $this->nullableString($instagramProfile->profile_image_hash)
            : $this->nullableString($value);
    }

    public function getInstagramFollowersCountAttribute($value): ?int
    {
        return $this->resolvedInstagramMetric('followers_count', $value);
    }

    public function getInstagramFollowingCountAttribute($value): ?int
    {
        return $this->resolvedInstagramMetric('following_count', $value);
    }

    public function getInstagramPostsCountAttribute($value): ?int
    {
        return $this->resolvedInstagramMetric('posts_count', $value);
    }

    public function getLastInstagramAnalyzedAtAttribute($value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        $timezone = config('app.timezone');
        $asUtc = Carbon::parse($value, 'UTC')->timezone($timezone);

        if ($asUtc->greaterThan(now($timezone)->addMinute())) {
            return Carbon::parse($value, $timezone);
        }

        return $asUtc;
    }

    public function analyzeInstagram(?callable $progress = null, bool $fullScan = false): TrackedPersonInstagramSnapshot
    {
        return app(TrackedPersonInstagramAnalysisService::class)->analyze($this, $progress, $fullScan);
    }

    public function instagramScanPreferences(): array
    {
        return self::normalizeInstagramScanPreferences(
            is_array($this->instagram_scan_preferences) ? $this->instagram_scan_preferences : [],
        );
    }

    public static function normalizeInstagramScanPreferences(array $preferences): array
    {
        $normalized = [
            ...self::INSTAGRAM_SCAN_PREFERENCE_DEFAULTS,
            ...array_intersect_key($preferences, self::INSTAGRAM_SCAN_PREFERENCE_DEFAULTS),
        ];

        $normalized['monitoring_scan_mode'] = in_array($normalized['monitoring_scan_mode'], ['mini', 'full'], true)
            ? $normalized['monitoring_scan_mode']
            : self::INSTAGRAM_SCAN_PREFERENCE_DEFAULTS['monitoring_scan_mode'];

        foreach ([
            'auto_scan_followers',
            'auto_scan_following',
            'auto_scan_posts',
            'auto_scan_suggestions',
            'auto_scan_on_changes',
            'auto_scan_on_interval',
        ] as $key) {
            $normalized[$key] = (bool) $normalized[$key];
        }

        $normalized['auto_scan_min_interval_minutes'] = max(
            0,
            min(10080, (int) $normalized['auto_scan_min_interval_minutes']),
        );
        $normalized['auto_scan_count_change_threshold'] = max(
            1,
            min(1000000, (int) $normalized['auto_scan_count_change_threshold']),
        );

        return $normalized;
    }

    private function normalizeSocialUsername(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $username = strtolower(trim((string) $value));
        $username = preg_replace('/^https?:\/\/(www\.)?instagram\.com\//i', '', $username) ?? $username;
        $username = trim(ltrim($username, '@'), "/ \t\n\r\0\x0B");
        $username = preg_replace('/[?#].*$/', '', $username) ?? $username;

        return $username !== '' ? $username : null;
    }

    private function resolvedInstagramMetric(string $attribute, mixed $fallback): ?int
    {
        $instagramProfile = $this->resolvedCurrentInstagramProfile();

        if ($instagramProfile) {
            $profileValue = $instagramProfile->getAttribute($attribute);

            return is_numeric($profileValue) ? (int) $profileValue : null;
        }

        return is_numeric($fallback) ? (int) $fallback : null;
    }

    private function resolvedCurrentInstagramProfile(): ?InstagramProfile
    {
        if ($this->relationLoaded('currentInstagramProfile')) {
            $profile = $this->getRelation('currentInstagramProfile');
        } elseif (! ($this->attributes['current_instagram_profile_id'] ?? null)) {
            return null;
        } else {
            $profile = $this->getRelationValue('currentInstagramProfile');
        }

        if (! $profile instanceof InstagramProfile) {
            return null;
        }

        $trackedUsername = $this->normalizeSocialUsername($this->attributes['instagram_username'] ?? null);
        $profileUsername = $this->normalizeSocialUsername($profile->username);

        return $trackedUsername !== null && $trackedUsername === $profileUsername
            ? $profile
            : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
