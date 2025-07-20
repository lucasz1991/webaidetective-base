<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Jobs\LogActivityJob;
use Illuminate\Support\Str;


class LogActivity
{
    public function handle($request, Closure $next)
    {
         $pathSlug = Str::slug($request->path());
         $islivewireupdate = ($pathSlug === 'livewireupdate');
        if (!$islivewireupdate) {
            dispatch(new LogActivityJob(auth()->user(), [
                'method' => $request->method(),
                'path' => $request->path(),
                'full_url' => $request->fullUrl(),
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ]));
        }
        return $next($request);
    }
}
