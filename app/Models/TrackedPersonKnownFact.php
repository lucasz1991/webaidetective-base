<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackedPersonKnownFact extends Model
{
    use HasFactory;

    protected $fillable = [
        'tracked_person_id',
        'user_id',
        'label',
        'value',
        'source',
        'notes',
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
