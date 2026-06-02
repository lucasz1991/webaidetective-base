<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TrackedPersonInstagramProfileLink extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'tracked_person_id',
        'instagram_profile_id',
        'user_id',
        'relation_type',
        'is_current',
        'linked_at',
        'unlinked_at',
        'notes',
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'linked_at' => 'datetime',
        'unlinked_at' => 'datetime',
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
}
