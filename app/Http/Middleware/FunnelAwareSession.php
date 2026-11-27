<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;

class FunnelAwareSession extends StartSession
{
    public function __construct(SessionManager $manager)
    {
        parent::__construct($manager, fn () => app(Factory::class));
    }

    protected function handleStatefulRequest(Request $request, $session, Closure $next)
    {
        if (! $request->routeIs('public-funnel.stage')) {
            return parent::handleStatefulRequest($request, $session, $next);
        }
        // A background diagnostics POST can finish AFTER a real signup or OAuth
        // rotates the session. Read the original session for normal CSRF checks
        // but never save it, age its flash data or return its old cookies.
        $request->setLaravelSession($this->startSession($request, $session));
        $response = $next($request);
        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }

        return $response;
    }
}
