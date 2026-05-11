<?php

namespace App\Http\Middleware;

use Closure;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth; 
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle($request, Closure $next, $role)
    {
        if (! Auth::check()) {
            return redirect(RouteServiceProvider::home());
        }

        if (Auth::user()->role === 'admin') {
            return $next($request);
        }

        if (Auth::user()->role !== $role) {
            return redirect(RouteServiceProvider::home());
        }

        return $next($request);
    }
}
