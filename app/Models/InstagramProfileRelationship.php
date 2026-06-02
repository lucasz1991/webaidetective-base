<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstagramProfileRelationship extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'source_instagram_profile_id',
        'related_instagram_profile_id',
        'first_seen_scan_id',
        'last_seen_scan_id',
        'removed_scan_id',
        'list_type',
        'status',
        'display_name_snapshot',
        'profile_url_snapshot',
        'first_seen_at',
        'last_seen_at',
        'removed_at',
        'evidence',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'removed_at' => 'datetime',
        'evidence' => 'array',
    ];

    public function sourceInstagramProfile(): BelongsTo
    {
        return $this->belongsTo(InstagramProfile::class, 'source_instagram_profile_id');
    }

    public function relatedInstagramProfile(): BelongsTo
    {
        return $this->belongsTo(InstagramProfile::class, 'related_instagram_profile_id');
    }

    public function firstSeenScan(): BelongsTo
    {
        return $this->belongsTo(InstagramProfileListScan::class, 'first_seen_scan_id');
    }

    public function lastSeenScan(): BelongsTo
    {
        return $this->belongsTo(InstagramProfileListScan::class, 'last_seen_scan_id');
    }

    public function removedScan(): BelongsTo
    {
        return $this->belongsTo(InstagramProfileListScan::class, 'removed_scan_id');
    }

    public function scanItems(): HasMany
    {
        return $this->hasMany(InstagramProfileListScanItem::class, 'relationship_id');
    }
}
