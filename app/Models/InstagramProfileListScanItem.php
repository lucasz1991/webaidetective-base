<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstagramProfileListScanItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'list_scan_id',
        'relationship_id',
        'source_instagram_profile_id',
        'related_instagram_profile_id',
        'list_type',
        'item_status',
        'username_snapshot',
        'display_name_snapshot',
        'profile_url_snapshot',
        'raw_item',
        'observed_at',
    ];

    protected $casts = [
        'raw_item' => 'array',
        'observed_at' => 'datetime',
    ];

    public function listScan(): BelongsTo
    {
        return $this->belongsTo(InstagramProfileListScan::class, 'list_scan_id');
    }

    public function relationship(): BelongsTo
    {
        return $this->belongsTo(InstagramProfileRelationship::class);
    }

    public function sourceInstagramProfile(): BelongsTo
    {
        return $this->belongsTo(InstagramProfile::class, 'source_instagram_profile_id');
    }

    public function relatedInstagramProfile(): BelongsTo
    {
        return $this->belongsTo(InstagramProfile::class, 'related_instagram_profile_id');
    }
}
