<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstagramScanEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'scan_type',
        'scan_id',
        'instagram_username',
        'tracked_person_id',
        'user_id',
        'phase',
        'stage',
        'status_level',
        'percent',
        'message',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'scan_id' => 'integer',
        'tracked_person_id' => 'integer',
        'user_id' => 'integer',
        'percent' => 'integer',
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function trackedPerson(): BelongsTo
    {
        return $this->belongsTo(TrackedPerson::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
