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
        if ($response->getStatusCode() !== 200 || ! $funnel->entrySurface($request) || ! $funnel->isPageNavigation($request)) {
            return $response;
        }
        $funnel->page($request, $request->routeIs('register') ? 'signup_viewed' : 'landing_view');

        return $response;
    }
}
