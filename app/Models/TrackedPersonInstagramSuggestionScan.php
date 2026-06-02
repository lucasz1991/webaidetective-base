<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackedPersonInstagramSuggestionScan extends Model
{
    use HasFactory;

    protected $fillable = [
        'tracked_person_id',
        'user_id',
        'target_username',
        'status_level',
        'status_message',
        'suggestions_observed_count',
        'suggestions_checked_count',
        'suggestion_matches_count',
        'gracefully_stopped',
        'raw_payload',
        'analyzed_at',
    ];

    protected $casts = [
        'suggestions_observed_count' => 'integer',
        'suggestions_checked_count' => 'integer',
        'suggestion_matches_count' => 'integer',
        'gracefully_stopped' => 'boolean',
        'raw_payload' => 'array',
        'analyzed_at' => 'datetime',
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
