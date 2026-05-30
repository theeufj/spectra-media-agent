<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DetectTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = env('TENANT_OVERRIDE')
            ? env('TENANT_OVERRIDE')
            : strtolower(preg_replace('/^www\./i', '', $request->getHost()));

        $tenants = config('tenants');
        $defaultKey = $tenants['default'] ?? 'sitetospend.com';

        $config = $tenants[$host] ?? $tenants[$defaultKey] ?? $tenants['sitetospend.com'];

        $request->attributes->set('tenant', $config);

        return $next($request);
    }
}
