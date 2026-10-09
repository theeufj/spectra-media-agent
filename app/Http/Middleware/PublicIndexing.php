<?php

namespace App\Http\Middleware;

use App\Support\PublicSeo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $robots = PublicSeo::robots($request);

        if ($response->getStatusCode() >= 400) {
            $robots = 'noindex, nofollow';
        }

        if ($robots !== null) {
            $response->headers->set('X-Robots-Tag', $robots);
        }

        return $response;
    }
}
