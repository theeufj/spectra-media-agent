<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hold admin access behind a second factor.
 *
 * Enforcement is deliberately staged rather than absolute, because a hard
 * requirement applied at deploy time locks out every existing admin — including
 * whoever would need to be logged in to fix it. So:
 *
 *   - an admin without two-factor is sent to enrol, and can reach only the
 *     enrolment routes until they have;
 *   - an admin with two-factor must pass a challenge once per session.
 *
 * Set ADMIN_REQUIRE_2FA=false to fall back to challenging only those who have
 * already enrolled — useful for the window between deploying this and everyone
 * having set it up.
 */
class RequireTwoFactor
{
    /** How long a passed challenge lasts before it is asked again. */
    private const SESSION_LIFETIME_MINUTES = 720;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // The enrolment and challenge screens must stay reachable, or the
        // requirement becomes a locked door with the key behind it.
        if ($request->routeIs('admin.two-factor.*')) {
            return $next($request);
        }

        if ($user->hasTwoFactorEnabled()) {
            return $this->hasPassedRecently($request)
                ? $next($request)
                : redirect()->guest(route('admin.two-factor.challenge'));
        }

        if (config('auth.admin_require_2fa', true)) {
            return redirect()->route('admin.two-factor.show')->with('flash', [
                'type' => 'error',
                'message' => 'Set up two-factor authentication to use the admin console.',
            ]);
        }

        return $next($request);
    }

    private function hasPassedRecently(Request $request): bool
    {
        $passedAt = $request->session()->get('two_factor_passed_at');

        if (! $passedAt) {
            return false;
        }

        return (now()->timestamp - (int) $passedAt) < self::SESSION_LIFETIME_MINUTES * 60;
    }
}
