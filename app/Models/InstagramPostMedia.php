<?php

namespace App\Models;

use App\Support\PublicAssetUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstagramPostMedia extends Model
{
    use HasFactory;

    protected $table = 'instagram_post_media';

    protected $fillable = [
        'instagram_post_id',
        'position',
        'media_type',
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
        'download_status',
        'download_error',
        'downloaded_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration_ms' => 'integer',
        'downloaded_at' => 'datetime',
    ];

    protected $appends = [
        'media_url',
        'preview_media_url',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(InstagramPost::class, 'instagram_post_id');
    }

    public function getMediaUrlAttribute(): ?string
    {
        return PublicAssetUrl::fromStorageOrRemote($this->storage_path, $this->source_url);
    }

    public function getPreviewMediaUrlAttribute(): ?string
    {
        return PublicAssetUrl::fromStorageOrRemote(
            $this->preview_storage_path ?: ($this->media_type === 'image' ? $this->storage_path : null),
            $this->preview_url,
        );
    }
}
