<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'max_profiles',
        'max_users',
        'monthly_credits',
        'max_history_days',
        'scan_frequency_minutes',
        'priority_level',
        'features',
    ];

    protected $casts = [
        'max_profiles' => 'integer',
        'max_users' => 'integer',
        'monthly_credits' => 'integer',
        'max_history_days' => 'integer',
        'scan_frequency_minutes' => 'integer',
        'priority_level' => 'integer',
        'features' => 'array',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
