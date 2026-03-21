<?php

namespace App\Http\Controllers;

use App\Models\AgentActivity;
use Illuminate\Http\Request;

class AgentActivityController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $customerId = session('active_customer_id');

        if (!$customerId || !$user->customers()->where('customers.id', $customerId)->exists()) {
            return response()->json(['data' => []]);
        }

        $activities = AgentActivity::where('customer_id', $customerId)
            ->when($request->input('campaign_id'), fn ($q, $id) => $q->where('campaign_id', $id))
            ->orderBy('created_at', 'desc')
            ->limit($request->input('limit', 20))
            ->get();

        return response()->json(['data' => $activities]);
    }
}
