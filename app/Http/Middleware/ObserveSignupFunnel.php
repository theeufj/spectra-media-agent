<?php

namespace App\Http\Middleware;

use App\Services\SignupFunnel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ObserveSignupFunnel
{
    public function handle(Request $request, Closure $next): Response
    {
        $funnel = app(SignupFunnel::class);
        if (! $funnel->ownHost($request)) {
            return $next($request);
        }
        if ($funnel->optedOut($request)) {
            $funnel->suppress($request);

            return $next($request);
        }
        $response = $next($request);
        if ($request->getMethod() !== 'GET' || $response->getStatusCode() !== 200
            || ! $request->routeIs('landing', 'google-ads-management', 'register')
            || ! ($request->acceptsHtml() || $request->header('X-Inertia') === 'true')
            || $request->prefetch() || $request->hasHeader('X-Inertia-Partial-Component')
            || preg_match('/prefetch|prerender/i', $request->header('Sec-Purpose', '').' '.$request->header('Purpose', ''))
            || preg_match('/bot\b|crawler|spider|facebookexternalhit|headlesschrome|lighthouse|previewbot|curl\/|wget\//i', (string) $request->userAgent())) {
            return $response;
        }
        $funnel->page($request, $request->routeIs('register') ? 'signup_viewed' : 'landing_view');

        return $response;
    }
}
