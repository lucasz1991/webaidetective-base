<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerFollower extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'follower_id',
        'date'
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function follower()
    {
        return $this->belongsTo(Customer::class, 'follower_id');
    }
}
