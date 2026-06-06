<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstagramPostMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'instagram_post_id',
        'instagram_post_scan_id',
        'likes_count',
        'comments_count',
        'observed_at',
    ];

    protected $casts = [
        'likes_count' => 'integer',
        'comments_count' => 'integer',
        'observed_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(InstagramPost::class, 'instagram_post_id');
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(InstagramPostScan::class, 'instagram_post_scan_id');
    }
}
