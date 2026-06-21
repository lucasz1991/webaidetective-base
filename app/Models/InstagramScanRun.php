<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstagramScanRun extends Model
{
    use HasFactory;

    public const STATUS_RUNNING = 'running';

    public const STATUS_RETRY_SCHEDULED = 'retry_scheduled';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tracked_person_id',
        'instagram_profile_id',
        'user_id',
        'scan_context_id',
        'scan_context_key',
        'generation',
        'scan_type',
        'label',
        'target_username',
        'status',
        'attempt',
        'started_at',
        'finished_at',
        'last_heartbeat_at',
        'last_process_output_at',
        'next_retry_at',
        'last_error',
        'node_processes',
        'resume_payload',
    ];

    protected $casts = [
        'tracked_person_id' => 'integer',
        'instagram_profile_id' => 'integer',
        'user_id' => 'integer',
        'scan_context_id' => 'integer',
        'generation' => 'integer',
        'attempt' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'last_process_output_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'node_processes' => 'array',
        'resume_payload' => 'array',
    ];

    public function trackedPerson(): BelongsTo
    {
        return $this->belongsTo(TrackedPerson::class);
    }

    public function instagramProfile(): BelongsTo
    {
        return $this->belongsTo(InstagramProfile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeRetryDue(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_RETRY_SCHEDULED)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now('UTC'));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_RUNNING,
            self::STATUS_QUEUED,
        ]);
    }
}
