<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrackedPersonInstagramPublicProfileScan extends Model
{
    use HasFactory;

    protected $fillable = [
        'tracked_person_id',
        'public_profile_id',
        'user_id',
        'target_username',
        'public_username',
        'relation_type',
        'public_profile_follows_target',
        'target_follows_public_profile',
        'followers_checked',
        'followers_available',
        'followers_complete',
        'followers_observed_count',
        'followers_expected_count',
        'followers_match',
        'following_checked',
        'following_available',
        'following_complete',
        'following_observed_count',
        'following_expected_count',
        'following_match',
        'status_level',
        'status_message',
        'raw_payload',
        'analyzed_at',
    ];

    protected $casts = [
        'public_profile_follows_target' => 'boolean',
        'target_follows_public_profile' => 'boolean',
        'followers_checked' => 'boolean',
        'followers_available' => 'boolean',
        'followers_complete' => 'boolean',
        'followers_match' => 'array',
        'following_checked' => 'boolean',
        'following_available' => 'boolean',
        'following_complete' => 'boolean',
        'following_match' => 'array',
        'raw_payload' => 'array',
        'analyzed_at' => 'datetime',
    ];

    protected $appends = [
        'relation_label',
    ];

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

    public function inferredConnections(): HasMany
    {
        return $this->hasMany(TrackedPersonInstagramInferredConnection::class, 'scan_id');
    }

    public function getRelationLabelAttribute(): string
    {
        return match ($this->relation_type) {
            'mutual' => 'Gegenseitig bestaetigt',
            'public_follows_target' => 'Profil folgt dieser Person',
            'target_follows_public' => 'Person folgt diesem Profil',
            'candidate_search' => 'Teilrekonstruktion aus bekannten Listen',
            'none' => 'Keine direkte Listenverbindung',
            default => 'Ungeklaert',
        };
    }
}
