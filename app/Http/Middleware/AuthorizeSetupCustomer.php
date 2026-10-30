<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Conceal another customer's setup before the standard policy middleware. */
class AuthorizeSetupCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->route('customer');
        abort_unless($customer instanceof Customer && Gate::allows('update', $customer), 404);
        abort_unless($customer->service_type === 'setup_only', 404);

        return $next($request);
    }
}
