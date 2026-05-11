<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TrackedPersonInstagramMedia extends Model
{
    use HasFactory;

    protected $table = 'tracked_person_instagram_media';

    protected $fillable = [
        'tracked_person_instagram_snapshot_id',
        'tracked_person_id',
        'media_type',
        'is_profile_image',
        'sort_order',
        'source_url',
        'storage_path',
        'content_hash',
    ];

    protected $casts = [
        'is_profile_image' => 'boolean',
    ];

    protected $appends = [
        'storage_url',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(TrackedPersonInstagramSnapshot::class, 'tracked_person_instagram_snapshot_id');
    }

    public function trackedPerson(): BelongsTo
    {
        return $this->belongsTo(TrackedPerson::class);
    }

    public function getStorageUrlAttribute(): ?string
    {
        if (! $this->storage_path) {
            return null;
        }

        return Storage::disk('public')->url($this->storage_path);
    }
}
