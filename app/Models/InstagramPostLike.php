<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstagramPostLike extends Model
{
    use HasFactory;

    protected $fillable = [
        'instagram_post_id',
        'instagram_profile_id',
        'first_seen_scan_id',
        'last_seen_scan_id',
        'liker_key',
        'instagram_user_id',
        'username',
        'full_name',
        'profile_image_url',
        'is_verified',
        'is_active',
        'first_seen_at',
        'last_seen_at',
        'removed_at',
        'raw_like',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'removed_at' => 'datetime',
        'raw_like' => 'array',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(InstagramPost::class, 'instagram_post_id');
    }

    public function instagramProfile(): BelongsTo
    {
        return $this->belongsTo(InstagramProfile::class);
    }
}
