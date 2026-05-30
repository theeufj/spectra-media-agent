<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;

class LandingController extends Controller
{
    public function index(Request $request)
    {
        $plans = Plan::active()->ordered()->where('is_free', false)->get();
        $tenant = $request->attributes->get('tenant', config('tenants.' . config('tenants.default')));

        $page = ($tenant['key'] ?? '') === 'realpropertyads' ? 'RealEstateLanding' : 'Landing';

        return \Inertia\Inertia::render($page, [
            'plans' => $plans,
        ]);
    }

    public function features()
    {
        return \Inertia\Inertia::render('Features');
    }

    public function howItWorks()
    {
        return \Inertia\Inertia::render('HowItWorks');
    }

    public function pricing()
    {
        $plans = Plan::active()->ordered()->where('is_free', false)->get();

        return \Inertia\Inertia::render('Pricing', [
            'plans' => $plans,
        ]);
    }

    public function about()
    {
        return \Inertia\Inertia::render('About');
    }
}
