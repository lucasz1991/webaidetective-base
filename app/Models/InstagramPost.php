<?php

namespace App\Models;

use App\Support\PublicAssetUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstagramPost extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'instagram_profile_id',
        'first_seen_scan_id',
        'last_seen_scan_id',
        'shortcode',
        'media_pk',
        'media_type',
        'post_url',
        'thumbnail_url',
        'thumbnail_path',
        'media_count',
        'caption',
        'likes_count',
        'comments_count',
        'published_at',
        'first_seen_at',
        'last_seen_at',
        'last_scanned_at',
        'raw_post',
    ];

    protected $casts = [
        'likes_count' => 'integer',
        'comments_count' => 'integer',
        'media_count' => 'integer',
        'published_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_scanned_at' => 'datetime',
        'raw_post' => 'array',
    ];

    protected $appends = [
        'thumbnail_storage_url',
    ];

    public function instagramProfile(): BelongsTo
    {
        return $this->belongsTo(InstagramProfile::class);
    }

    public function firstSeenScan(): BelongsTo
    {
        return $this->belongsTo(InstagramPostScan::class, 'first_seen_scan_id');
    }

    public function lastSeenScan(): BelongsTo
    {
        return $this->belongsTo(InstagramPostScan::class, 'last_seen_scan_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(InstagramPostMedia::class)->orderBy('position');
    }

    public function primaryMedia(): HasOne
    {
        return $this->hasOne(InstagramPostMedia::class)->ofMany('position', 'min');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(InstagramPostMetric::class)->latest('observed_at');
    }

    public function latestMetric(): HasOne
    {
        return $this->hasOne(InstagramPostMetric::class)->latestOfMany('observed_at');
    }

    public function getThumbnailStorageUrlAttribute(): ?string
    {
        return PublicAssetUrl::fromStorageOrRemote($this->thumbnail_path, $this->thumbnail_url);
    }
}
