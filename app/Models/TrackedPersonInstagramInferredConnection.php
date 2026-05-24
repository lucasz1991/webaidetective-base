<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackedPersonInstagramInferredConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'tracked_person_id',
        'public_profile_id',
        'scan_id',
        'user_id',
        'source_public_username',
        'candidate_username',
        'candidate_display_name',
        'candidate_profile_url',
        'relationship_type',
        'source_lists',
        'evidence',
        'status',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'source_lists' => 'array',
        'evidence' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    protected $appends = [
        'relationship_label',
        'display_handle',
    ];

    public function trackedPerson(): BelongsTo
    {
        return $this->belongsTo(TrackedPerson::class);
    }

    public function publicProfile(): BelongsTo
    {
        return $this->belongsTo(TrackedPersonPublicProfile::class, 'public_profile_id');
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(TrackedPersonInstagramPublicProfileScan::class, 'scan_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getRelationshipLabelAttribute(): string
    {
        return match ($this->relationship_type) {
            'follows_target' => 'Moeglicher Follower',
            'followed_by_target' => 'Moeglich gefolgt',
            default => 'Ungeklaert',
        };
    }

    public function getDisplayHandleAttribute(): string
    {
        return '@'.ltrim((string) $this->candidate_username, '@');
    }
}
