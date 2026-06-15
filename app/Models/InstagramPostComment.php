<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstagramPostComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'instagram_post_id',
        'parent_comment_id',
        'instagram_profile_id',
        'first_seen_scan_id',
        'last_seen_scan_id',
        'instagram_comment_id',
        'parent_instagram_comment_id',
        'instagram_user_id',
        'username',
        'full_name',
        'profile_image_url',
        'comment_text',
        'likes_count',
        'is_verified',
        'is_active',
        'published_at',
        'first_seen_at',
        'last_seen_at',
        'removed_at',
        'raw_comment',
    ];

    protected $casts = [
        'likes_count' => 'integer',
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
        'published_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'removed_at' => 'datetime',
        'raw_comment' => 'array',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(InstagramPost::class, 'instagram_post_id');
    }

    public function instagramProfile(): BelongsTo
    {
        return $this->belongsTo(InstagramProfile::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_comment_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_comment_id')->orderBy('published_at');
    }
}
