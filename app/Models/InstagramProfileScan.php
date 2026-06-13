<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstagramProfileScan extends Model
{
    use HasFactory;

    protected $fillable = [
        'instagram_profile_id',
        'user_id',
        'scan_mode',
        'status_level',
        'status_message',
        'raw_payload',
        'scanned_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'scanned_at' => 'datetime',
    ];

    public function instagramProfile(): BelongsTo
    {
        return $this->belongsTo(InstagramProfile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
