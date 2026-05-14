<?php

namespace App\Models;

use App\Jobs\ProcessMailJob;
use Illuminate\Database\Eloquent\Model;

class Mail extends Model
{
    protected $fillable = [
        'type',
        'from_user_id',
        'status',
        'content',
        'recipients',
    ];

    protected $casts = [
        'content' => 'json',
        'recipients' => 'json',
        'status' => 'boolean',
    ];

    protected $attributes = [
        'type' => 'message',
    ];

    protected static function boot()
    {
        parent::boot();

        static::created(function (Mail $mail) {
            ProcessMailJob::dispatch($mail);
        });
    }
}
