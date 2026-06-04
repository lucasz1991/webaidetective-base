<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditWallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'available_credits',
        'reserved_credits',
        'used_credits',
        'bonus_credits',
        'last_reset_at',
    ];

    protected $casts = [
        'available_credits' => 'integer',
        'reserved_credits' => 'integer',
        'used_credits' => 'integer',
        'bonus_credits' => 'integer',
        'last_reset_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
