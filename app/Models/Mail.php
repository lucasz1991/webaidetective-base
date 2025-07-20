<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Jobs\ProcessMailJob;


class Mail extends Model
{
    protected $fillable = [
        'status', 'content', 'recipients'
    ];

    protected $casts = [
        'content' => 'json',
        'recipients' => 'json',
    ];

        // Event-Listener für das "created"-Ereignis
        protected static function boot()
        {
            parent::boot();
    
            static::created(function ($mail) {
                // Dispatch Job zur Verarbeitung der Mail
                ProcessMailJob::dispatch($mail);
            });
        }
}
