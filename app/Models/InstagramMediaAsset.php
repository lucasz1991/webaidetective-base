<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InstagramMediaAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'instagram_username',
        'media_role',
        'media_type',
        'source_url',
        'source_url_hash',
        'content_hash',
        'storage_path',
        'mime_type',
        'file_size',
        'metadata',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'metadata' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
