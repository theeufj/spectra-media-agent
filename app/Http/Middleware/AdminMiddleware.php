<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    /**
     * Support can look; only a full admin can change anything.
     *
     * The split is on the HTTP method rather than on a per-route annotation,
     * because a per-route list is a list somebody has to maintain — and the one
     * thing this codebase has demonstrated repeatedly is that a rule enforced in
     * one place holds, while a rule that must be remembered at every call site
     * does not. Every admin GET is a view; every admin POST, PUT or DELETE
     * changes something.
     *
     * Routes needing a narrower rule than "any full admin" should use a policy,
     * not another middleware parameter.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->canAccessAdmin()) {
            abort(403, 'Unauthorized action.');
        }

        // Managing your own second factor is not an admin action on the
        // platform — it is how you secure your own account. Support staff would
        // otherwise be unable to enrol, since every POST here requires a full
        // admin.
        if ($request->routeIs('admin.two-factor.*')) {
            return $next($request);
        }

        $isRead = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

        if (! $isRead && ! $user->isFullAdmin()) {
            abort(403, 'This action requires a full admin account.');
        }

        return $next($request);
    }
}
