<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstagramStoryScan extends Model
{
    use HasFactory;

    protected $fillable = [
        'instagram_profile_id',
        'tracked_person_id',
        'snapshot_id',
        'user_id',
        'instagram_username',
        'scan_type',
        'status_level',
        'status_message',
        'attempted',
        'available',
        'complete',
        'gracefully_stopped',
        'observed_count',
        'raw_payload',
        'scanned_at',
    ];

    protected $casts = [
        'attempted' => 'boolean',
        'available' => 'boolean',
        'complete' => 'boolean',
        'gracefully_stopped' => 'boolean',
        'observed_count' => 'integer',
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

    public function items(): HasMany
    {
        return $this->hasMany(InstagramStoryItem::class)->orderBy('position');
    }
}
