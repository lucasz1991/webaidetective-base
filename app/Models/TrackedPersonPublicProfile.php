<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class TrackedPersonPublicProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'tracked_person_id',
        'user_id',
        'platform',
        'username',
        'display_name',
        'relationship_type',
        'profile_url',
        'is_public',
        'notes',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    protected $appends = [
        'resolved_profile_url',
        'relationship_label',
        'display_handle',
    ];

    public function trackedPerson(): BelongsTo
    {
        return $this->belongsTo(TrackedPerson::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function instagramConnectionScans(): HasMany
    {
        return $this->hasMany(TrackedPersonInstagramPublicProfileScan::class, 'public_profile_id');
    }

    public function latestInstagramConnectionScan(): HasOne
    {
        return $this->hasOne(TrackedPersonInstagramPublicProfileScan::class, 'public_profile_id')
            ->latestOfMany('analyzed_at');
    }

    public function getResolvedProfileUrlAttribute(): ?string
    {
        if ($this->profile_url) {
            return $this->profile_url;
        }

        if (! $this->username) {
            return null;
        }

        return match ($this->platform) {
            'instagram' => 'https://www.instagram.com/'.ltrim($this->username, '@').'/',
            'tiktok' => 'https://www.tiktok.com/@'.ltrim($this->username, '@'),
            'facebook' => 'https://www.facebook.com/'.ltrim($this->username, '@'),
            'x' => 'https://x.com/'.ltrim($this->username, '@'),
            'youtube' => 'https://www.youtube.com/@'.ltrim($this->username, '@'),
            'snapchat' => 'https://www.snapchat.com/add/'.ltrim($this->username, '@'),
            default => null,
        };
    }

    public function getRelationshipLabelAttribute(): string
    {
        return match ($this->relationship_type) {
            'follows_target' => 'Folgt der Person',
            'followed_by_target' => 'Wird von der Person gefolgt',
            'mutual' => 'Gegenseitige Verbindung',
            default => 'Oeffentliche Verbindung',
        };
    }

    public function getDisplayHandleAttribute(): string
    {
        $username = trim((string) $this->username);

        if ($username === '') {
            return 'Unbekannt';
        }

        return Str::startsWith($username, '@') ? $username : '@'.$username;
    }
}
