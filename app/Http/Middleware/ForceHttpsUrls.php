<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceHttpsUrls
{
    /**
     * Handle an incoming request to ensure HTTPS URLs are generated.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Force the request to be recognized as secure when behind HTTPS proxy
        if ($request->header('x-forwarded-proto') === 'https' || $request->header('x-forwarded-scheme') === 'https') {
            $request->server->set('HTTPS', 'on');
        }
        
        // Ensure APP_URL is always HTTPS in production
        if (config('app.env') === 'production' && config('app.url')) {
            $url = config('app.url');
            if (strpos($url, 'http://') === 0) {
                config(['app.url' => 'https://' . substr($url, 7)]);
            }
        }

        return $next($request);
    }
}
