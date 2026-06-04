<?php

namespace App\Models;

use App\Support\PublicAssetUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class TrackedPersonInstagramSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'tracked_person_id',
        'instagram_profile_id',
        'instagram_username',
        'full_name',
        'biography',
        'posts_count',
        'followers_count',
        'following_count',
        'profile_image_url',
        'profile_image_path',
        'profile_image_hash',
        'screenshot_path',
        'html_path',
        'status_level',
        'status_message',
        'has_changes',
        'detected_changes',
        'raw_payload',
        'analyzed_at',
    ];

    protected $casts = [
        'has_changes' => 'boolean',
        'detected_changes' => 'array',
        'raw_payload' => 'array',
        'analyzed_at' => 'datetime',
    ];

    protected $appends = [
        'profile_image_storage_url',
        'screenshot_url',
        'profile_visibility',
        'is_private',
    ];

    public function trackedPerson(): BelongsTo
    {
        return $this->belongsTo(TrackedPerson::class);
    }

    public function instagramProfile(): BelongsTo
    {
        return $this->belongsTo(InstagramProfile::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(TrackedPersonInstagramMedia::class);
    }

    public function getProfileImageStorageUrlAttribute(): ?string
    {
        return PublicAssetUrl::fromStorageOrRemote($this->profile_image_path, $this->profile_image_url);
    }

    public function getScreenshotUrlAttribute(): ?string
    {
        if (! $this->screenshot_path) {
            return null;
        }

        return Storage::disk('public')->url($this->screenshot_path);
    }

    public function getProfileVisibilityAttribute(): string
    {
        $visibility = data_get($this->raw_payload, 'extractedProfile.profileVisibility');

        if (in_array($visibility, ['public', 'private'], true)) {
            return $visibility;
        }

        $isPrivate = data_get($this->raw_payload, 'extractedProfile.isPrivate');

        if ($isPrivate === true) {
            return 'private';
        }

        if ($isPrivate === false) {
            return 'public';
        }

        return 'unknown';
    }

    public function getIsPrivateAttribute(): ?bool
    {
        return match ($this->profile_visibility) {
            'private' => true,
            'public' => false,
            default => null,
        };
    }

    public function getAnalyzedAtAttribute($value): ?Carbon
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
}
