<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $activeCustomer = $user->customers()->findOrFail(session('active_customer_id'));

        $campaigns = $activeCustomer->campaigns()->orderBy('created_at', 'desc')->get();

        return Inertia::render('Dashboard/Index', [
            'campaigns' => $campaigns,
            'defaultCampaign' => $campaigns->first(),
        ]);
    }
}
