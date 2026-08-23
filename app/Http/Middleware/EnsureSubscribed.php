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
     * Teammate-aware: access counts if the user OR anyone on their active
     * customer account subscribes. Team members on a company plan carry no
     * subscription of their own — this middleware used to bounce them to
     * pricing before the controllers' teammate-aware checks could ever run,
     * and also locked them out of the billing pages they'd need to fix a
     * payment problem.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $customer = $user->customers()->find(session('active_customer_id'))
                ?? $user->customers()->first();

            if (! $user->hasSubscriptionAccess($customer)) {
                if ($request->wantsJson()) {
                    return response()->json(['message' => 'Subscription required.'], 403);
                }

                return redirect()->route('subscription.pricing')->with('flash', [
                    'type' => 'info',
                    'message' => 'A subscription is needed for this — choose a plan to continue.',
                ]);
            }
        }

        return $next($request);
    }
}
