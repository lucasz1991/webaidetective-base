<?php

namespace App\Models;

use App\Services\TrackedPeople\TrackedPersonInstagramAnalysisService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class TrackedPerson extends Model
{
    use HasFactory;

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

    public function instagramPublicProfileScans(): HasMany
    {
        return $this->hasMany(TrackedPersonInstagramPublicProfileScan::class);
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

    public function getDisplayNameAttribute(): string
    {
        return trim(collect([$this->first_name, $this->last_name])->implode(' '))
            ?: ($this->alias ?: 'Unbenannte Person');
    }

    public function getProfileImageUrlAttribute(): ?string
    {
        if (! $this->profile_image_path) {
            return null;
        }

        return Storage::disk('public')->url($this->profile_image_path);
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
}
