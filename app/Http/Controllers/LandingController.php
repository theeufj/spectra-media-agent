<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LandingController extends Controller
{
    public function index()
    {
        return \Inertia\Inertia::render('Landing');
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
        return \Inertia\Inertia::render('Pricing');
    }

    public function about()
    {
        return \Inertia\Inertia::render('About');
    }
}
