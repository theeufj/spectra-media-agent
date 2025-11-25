<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscribed
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is subscribed via Cashier OR has active status in DB
        if ($request->user() && 
            ! $request->user()->subscribed('default') && 
            $request->user()->subscription_status !== 'active') {
            
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Subscription required.'], 403);
            }
            
            return redirect()->route('subscription.pricing');
        }

        return $next($request);
    }
}
