<?php

namespace App\Models;

use App\Support\PublicAssetUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    public function publicProfileLinks(): HasMany
    {
        return $this->hasMany(TrackedPersonPublicProfile::class);
    }

    public function listScans(): HasMany
    {
        return $this->hasMany(InstagramProfileListScan::class);
    }

    public function profileScans(): HasMany
    {
        return $this->hasMany(InstagramProfileScan::class);
    }

    public function suggestionScans(): HasMany
    {
        return $this->hasMany(TrackedPersonInstagramSuggestionScan::class);
    }

    public function postScans(): HasMany
    {
        return $this->hasMany(InstagramPostScan::class);
    }

    public function storyScans(): HasMany
    {
        return $this->hasMany(InstagramStoryScan::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(InstagramPost::class);
    }

    public function sourceRelationships(): HasMany
    {
        return $this->hasMany(InstagramProfileRelationship::class, 'source_instagram_profile_id');
    }

    public function relatedRelationships(): HasMany
    {
        return $this->hasMany(InstagramProfileRelationship::class, 'related_instagram_profile_id');
    }

    public function candidateInferredConnections(): HasMany
    {
        return $this->hasMany(TrackedPersonInstagramInferredConnection::class, 'candidate_instagram_profile_id');
    }

    public function sourceInferredConnections(): HasMany
    {
        return $this->hasMany(TrackedPersonInstagramInferredConnection::class, 'source_public_instagram_profile_id');
    }

    public function setUsernameAttribute($value): void
    {
        $this->attributes['username'] = $this->normalizeUsername($value);
    }

    public function getDisplayHandleAttribute(): string
    {
        return '@'.ltrim((string) $this->username, '@');
    }

    public function getProfileImageStorageUrlAttribute(): ?string
    {
        return PublicAssetUrl::fromStorageOrRemote($this->profile_image_path, $this->profile_image_url);
    }

    private function normalizeUsername(mixed $value): ?string
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
}
