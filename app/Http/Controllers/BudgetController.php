<?php

namespace App\Http\Controllers;

use App\Models\CrossChannelRebalanceLog;
use App\Models\PlatformBudgetAllocation;
use App\Services\CrossChannelBudgetAllocator;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BudgetController extends Controller
{
    public function allocator(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');
        $allocator = new CrossChannelBudgetAllocator();
        $analysis = $allocator->analyze($customer);

        return Inertia::render('Budget/Allocator', [
            'allocation' => $analysis['allocation'],
            'snapshot' => $analysis['snapshot'],
            'recommendations' => $analysis['recommendations'],
        ]);
    }

    public function updateAllocation(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');
        $validated = $request->validate([
            'total_monthly_budget' => 'required|numeric|min:0',
            'google_ads_pct' => 'required|numeric|min:0|max:100',
            'facebook_ads_pct' => 'required|numeric|min:0|max:100',
            'microsoft_ads_pct' => 'required|numeric|min:0|max:100',
            'strategy' => 'required|in:performance,equal,manual,roas_target',
            'target_roas' => 'nullable|numeric|min:0',
            'target_cpa' => 'nullable|numeric|min:0',
            'auto_rebalance' => 'boolean',
            'rebalance_frequency' => 'in:daily,weekly,monthly',
        ]);

        // Normalize percentages to 100
        $total = $validated['google_ads_pct'] + $validated['facebook_ads_pct'] + $validated['microsoft_ads_pct'];
        if ($total > 0 && abs($total - 100) > 0.5) {
            $validated['google_ads_pct'] = round($validated['google_ads_pct'] / $total * 100, 1);
            $validated['facebook_ads_pct'] = round($validated['facebook_ads_pct'] / $total * 100, 1);
            $validated['microsoft_ads_pct'] = round($validated['microsoft_ads_pct'] / $total * 100, 1);
        }

        PlatformBudgetAllocation::updateOrCreate(
            ['customer_id' => $customer->id],
            $validated
        );

        return back()->with('success', 'Budget allocation updated.');
    }

    public function rebalance(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');
        $allocator = new CrossChannelBudgetAllocator();
        $result = $allocator->rebalance($customer, 'manual');

        return back()->with('success', $result['status'] === 'rebalanced' ? 'Budget rebalanced successfully.' : 'No changes needed.');
    }

    public function history(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');
        $logs = CrossChannelRebalanceLog::where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return Inertia::render('Budget/History', [
            'logs' => $logs,
        ]);
    }
}
