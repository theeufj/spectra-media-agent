<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DetectTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        // config(), not env(): Forge runs config:cache on deploy, after which
        // the .env file is never loaded and env() returns its default. Reading
        // it here meant the override silently did nothing in production while
        // appearing to work locally.
        $tenants = config('tenants');

        $override = config('tenants.override');

        $host = $override
            ? strtolower($override)
            : strtolower(preg_replace('/^www\./i', '', $request->getHost()));

        // The fallback skin no longer reads the same key as the override, so
        // previewing one vertical cannot re-skin every unrecognised host to it.
        $defaultKey = config('tenants.default', 'sitetospend.com');

        $config = $tenants[$host] ?? $tenants[$defaultKey] ?? $tenants['sitetospend.com'];

        $request->attributes->set('tenant', $config);

        return $next($request);
    }
}
