<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstagramPostScan extends Model
{
    use HasFactory;

    protected $fillable = [
        'instagram_profile_id',
        'tracked_person_id',
        'snapshot_id',
        'user_id',
        'status_level',
        'status_message',
        'attempted',
        'available',
        'complete',
        'rate_limited',
        'gracefully_stopped',
        'observed_count',
        'new_count',
        'updated_count',
        'unchanged_count',
        'raw_payload',
        'scanned_at',
    ];

    protected $casts = [
        'attempted' => 'boolean',
        'available' => 'boolean',
        'complete' => 'boolean',
        'rate_limited' => 'boolean',
        'gracefully_stopped' => 'boolean',
        'observed_count' => 'integer',
        'new_count' => 'integer',
        'updated_count' => 'integer',
        'unchanged_count' => 'integer',
        'raw_payload' => 'array',
        'scanned_at' => 'datetime',
    ];

    public function instagramProfile(): BelongsTo
    {
        return $this->belongsTo(InstagramProfile::class);
    }

    public function trackedPerson(): BelongsTo
    {
        return $this->belongsTo(TrackedPerson::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(TrackedPersonInstagramSnapshot::class, 'snapshot_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function firstSeenPosts(): HasMany
    {
        return $this->hasMany(InstagramPost::class, 'first_seen_scan_id');
    }

    public function lastSeenPosts(): HasMany
    {
        return $this->hasMany(InstagramPost::class, 'last_seen_scan_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(InstagramPostMetric::class, 'instagram_post_scan_id');
    }

    public function firstSeenLikes(): HasMany
    {
        return $this->hasMany(InstagramPostLike::class, 'first_seen_scan_id');
    }

    public function firstSeenComments(): HasMany
    {
        return $this->hasMany(InstagramPostComment::class, 'first_seen_scan_id');
    }
}
