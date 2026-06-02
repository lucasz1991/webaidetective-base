<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class InstagramProfile extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'username',
        'display_name',
        'full_name',
        'biography',
        'profile_url',
        'profile_image_url',
        'profile_image_path',
        'profile_image_hash',
        'is_private',
        'profile_visibility',
        'followers_count',
        'following_count',
        'posts_count',
        'last_status_level',
        'last_status_message',
        'last_scanned_at',
        'raw_profile',
    ];

    protected $casts = [
        'is_private' => 'boolean',
        'followers_count' => 'integer',
        'following_count' => 'integer',
        'posts_count' => 'integer',
        'last_scanned_at' => 'datetime',
        'raw_profile' => 'array',
    ];

    protected $appends = [
        'display_handle',
        'profile_image_storage_url',
    ];

    public function trackedPersonLinks(): HasMany
    {
        return $this->hasMany(TrackedPersonInstagramProfileLink::class);
    }

    public function listScans(): HasMany
    {
        return $this->hasMany(InstagramProfileListScan::class);
    }

    public function sourceRelationships(): HasMany
    {
        return $this->hasMany(InstagramProfileRelationship::class, 'source_instagram_profile_id');
    }

    public function relatedRelationships(): HasMany
    {
        return $this->hasMany(InstagramProfileRelationship::class, 'related_instagram_profile_id');
    }

    public function getDisplayHandleAttribute(): string
    {
        return '@'.ltrim((string) $this->username, '@');
    }

    public function getProfileImageStorageUrlAttribute(): ?string
    {
        if (! $this->profile_image_path) {
            return null;
        }

        return Storage::disk('public')->url($this->profile_image_path);
    }
}
