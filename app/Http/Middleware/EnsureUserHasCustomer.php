<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasCustomer
{
    /**
     * Routes a customer-less user is allowed to reach, because they are the
     * ones that create the customer.
     *
     * @var list<string>
     */
    private const ONBOARDING_ROUTES = [
        'quick-start',
        'quick-start.process',
        'customers.create',
        'customers.store',
    ];

    /**
     * Handle an incoming request.
     *
     * Send new accounts to the quick start (paste a URL, we scan the site),
     * not to the ten-field manual form. The manual form stays reachable as the
     * "set up manually" link on the quick start page.
     *
     * This middleware runs before DashboardController, so its own
     * customer-less redirect to quick-start never fired for anyone landing on
     * /dashboard — which is every email/password signup after verification.
     * Only Google OAuth users, redirected explicitly by GoogleController, ever
     * saw the quick start at all.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()
            && ! $request->routeIs(...self::ONBOARDING_ROUTES)
            && $request->user()->customers()->doesntExist()) {
            return redirect()->route('quick-start');
        }

        return $next($request);
    }
}
