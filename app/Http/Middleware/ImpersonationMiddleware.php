<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ImpersonationMiddleware
{
    /**
     * Handle an incoming request.
     * Check if admin is impersonating a user and swap the auth user.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (session()->has('impersonate_user_id')) {
            $impersonatedUser = \App\Models\User::find(session('impersonate_user_id'));
            
            if ($impersonatedUser) {
                auth()->setUser($impersonatedUser);
            }
        }

        return $next($request);
    }
}
