<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'subject',
        'message',
        'from_user',
        'to_user',
        'status',
    ];

    /**
     * Get the user who sent the message.
     */
    public function sender()
    {
        return $this->belongsTo(User::class, 'from_user');
    }

    /**
     * Get the user who received the message.
     */
    public function recipient()
    {
        return $this->belongsTo(User::class, 'to_user');
    }
}
