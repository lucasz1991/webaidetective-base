<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstagramProfileListScan extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'instagram_profile_id',
        'tracked_person_id',
        'snapshot_id',
        'user_id',
        'list_type',
        'scan_mode',
        'status_level',
        'status_message',
        'attempted',
        'available',
        'complete',
        'rate_limited',
        'gracefully_stopped',
        'expected_count',
        'observed_count',
        'active_count',
        'known_count',
        'added_count',
        'removed_count',
        'search_attempted',
        'search_rounds',
        'raw_payload',
        'scanned_at',
    ];

    protected $casts = [
        'attempted' => 'boolean',
        'available' => 'boolean',
        'complete' => 'boolean',
        'rate_limited' => 'boolean',
        'gracefully_stopped' => 'boolean',
        'expected_count' => 'integer',
        'observed_count' => 'integer',
        'active_count' => 'integer',
        'known_count' => 'integer',
        'added_count' => 'integer',
        'removed_count' => 'integer',
        'search_attempted' => 'boolean',
        'search_rounds' => 'integer',
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
        return $this->hasMany(InstagramProfileListScanItem::class, 'list_scan_id');
    }
}
