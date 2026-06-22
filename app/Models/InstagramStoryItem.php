<?php

namespace App\Models;

use App\Support\PublicAssetUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstagramStoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'instagram_story_scan_id',
        'instagram_profile_id',
        'item_key',
        'source_type',
        'highlight_id',
        'highlight_title',
        'position',
        'media_type',
        'story_url',
        'source_url',
        'preview_url',
        'storage_path',
        'preview_storage_path',
        'mime_type',
        'file_size',
        'content_hash',
        'width',
        'height',
        'duration_ms',
        'text',
        'published_at',
        'download_status',
        'download_error',
        'downloaded_at',
        'raw_item',
    ];

    protected $casts = [
        'position' => 'integer',
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration_ms' => 'integer',
        'published_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'raw_item' => 'array',
    ];

    protected $appends = [
        'media_url',
        'preview_media_url',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(InstagramStoryScan::class, 'instagram_story_scan_id');
    }

    public function instagramProfile(): BelongsTo
    {
        return $this->belongsTo(InstagramProfile::class);
    }

    public function getMediaUrlAttribute(): ?string
    {
        return PublicAssetUrl::fromStorageOrRemote($this->storage_path, $this->source_url);
    }

    public function getPreviewMediaUrlAttribute(): ?string
    {
        return PublicAssetUrl::fromStorageOrRemote(
            $this->preview_storage_path ?: ($this->media_type === 'image' ? $this->storage_path : null),
            $this->preview_url ?: ($this->media_type === 'image' ? $this->source_url : null),
        );
    }
}
