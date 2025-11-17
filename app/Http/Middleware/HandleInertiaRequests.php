<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        if ($user && !$request->session()->has('active_customer_id') && $user->customers->isNotEmpty()) {
            $request->session()->put('active_customer_id', $user->customers->first()->id);
        }

        $activeCustomerId = $request->session()->get('active_customer_id');
        $activeCustomer = $user ? $user->customers()->find($activeCustomerId) : null;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'subscription_plan' => $user->subscription_plan,
                    'isAdmin' => $user->hasRole('admin'),
                    'customers' => $user->customers->map(function ($customer) {
                        return [
                            'id' => $customer->id,
                            'name' => $customer->name,
                            'role' => $customer->pivot->role,
                        ];
                    }),
                    'active_customer' => $activeCustomer,
                ] : null,
            ],
            'flash' => [
                'type' => $request->session()->get('flash.type'),
                'message' => $request->session()->get('flash.message'),
            ],
        ];
    }
}
