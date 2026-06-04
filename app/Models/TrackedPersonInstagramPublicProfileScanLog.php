<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackedPersonInstagramPublicProfileScanLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'scan_id',
        'tracked_person_id',
        'public_profile_id',
        'user_id',
        'event_type',
        'status_level',
        'stage',
        'message',
        'detail',
        'context',
        'logged_at',
    ];

    protected $casts = [
        'context' => 'array',
        'logged_at' => 'datetime',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(TrackedPersonInstagramPublicProfileScan::class, 'scan_id');
    }

    public function trackedPerson(): BelongsTo
    {
        return $this->belongsTo(TrackedPerson::class);
    }

    public function publicProfile(): BelongsTo
    {
        return $this->belongsTo(TrackedPersonPublicProfile::class, 'public_profile_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
