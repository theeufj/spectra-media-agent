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
            'usageStats' => [
                'free_generations_used' => $user->free_generations_used,
                'cro_audits_used' => $activeCustomer->cro_audits_used,
                'subscription_status' => $user->subscribed('default') ? 'active' : 'inactive',
            ]
        ]);
    }
}
