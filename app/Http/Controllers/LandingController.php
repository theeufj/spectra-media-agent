<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;

class LandingController extends Controller
{
    public function index()
    {
        $plans = Plan::active()->ordered()->where('is_free', false)->get();

        return \Inertia\Inertia::render('Landing', [
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
