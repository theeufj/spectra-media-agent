<?php

namespace App\Http\Controllers;

use App\Jobs\RunSandboxSimulation;
use App\Models\Customer;
use App\Services\Testing\SyntheticDataService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SandboxController extends Controller
{
    public function index(Request $request, SyntheticDataService $service)
    {
        $user = $request->user();

        $existingSandbox = Customer::sandbox()
            ->whereHas('users', fn($q) => $q->where('user_id', $user->id))
            ->first();

        return Inertia::render('Sandbox/Index', [
            'scenarios' => $service->getScenarios(),
            'existingSandbox' => $existingSandbox ? [
                'id' => $existingSandbox->id,
                'name' => $existingSandbox->name,
                'expires_at' => $existingSandbox->sandbox_expires_at?->toIso8601String(),
                'has_results' => !empty($existingSandbox->sandbox_results),
                'campaign_count' => $existingSandbox->campaigns()->count(),
            ] : null,
        ]);
    }

    public function launch(Request $request, SyntheticDataService $service)
    {
        $user = $request->user();

        $customer = $service->generateSandboxForUser($user);

        RunSandboxSimulation::dispatch($customer);

        return redirect()->route('sandbox.results', $customer->id)
            ->with('success', 'Sandbox created! Agents are now analyzing your campaigns...');
    }

    public function results(Request $request, Customer $customer)
    {
        $user = $request->user();

        // Verify user owns this sandbox
        if (!$customer->is_sandbox || !$customer->users()->where('user_id', $user->id)->exists()) {
            abort(403);
        }

        $campaigns = $customer->campaigns()->get()->map(fn($campaign) => [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'platform' => $this->detectPlatform($campaign),
            'daily_budget' => $campaign->daily_budget,
            'reason' => $campaign->reason,
            'goals' => $campaign->goals,
        ]);

        // Get agent activities for this sandbox, grouped by agent type
        $agentResults = \App\Models\AgentActivity::where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('agent_type')
            ->map(fn($activities) => $activities->map(fn($a) => [
                'action' => $a->action,
                'description' => $a->description,
                'details' => $a->details,
                'status' => $a->status,
                'campaign_id' => $a->campaign_id,
                'created_at' => $a->created_at->toIso8601String(),
            ]));

        // Aggregate performance summary from sandbox data
        $performanceSummary = $this->buildPerformanceSummary($customer);

        return Inertia::render('Sandbox/Results', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'expires_at' => $customer->sandbox_expires_at?->toIso8601String(),
                'sandbox_results' => $customer->sandbox_results,
            ],
            'campaigns' => $campaigns,
            'agentResults' => $agentResults,
            'performanceSummary' => $performanceSummary,
            'simulationComplete' => !empty($customer->sandbox_results),
        ]);
    }

    public function destroy(Request $request, Customer $customer, SyntheticDataService $service)
    {
        $user = $request->user();

        if (!$customer->is_sandbox || !$customer->users()->where('user_id', $user->id)->exists()) {
            abort(403);
        }

        $service->deleteSandboxCustomer($customer);

        return redirect()->route('sandbox.index')
            ->with('success', 'Sandbox environment deleted.');
    }

    protected function detectPlatform(object $campaign): string
    {
        if ($campaign->google_ads_campaign_id) return 'google';
        if ($campaign->facebook_ads_campaign_id) return 'facebook';
        if ($campaign->microsoft_ads_campaign_id) return 'microsoft';
        if ($campaign->linkedin_campaign_id) return 'linkedin';
        return 'unknown';
    }

    protected function buildPerformanceSummary(Customer $customer): array
    {
        $campaignIds = $customer->campaigns()->pluck('id');

        $google = \App\Models\GoogleAdsPerformanceData::whereIn('campaign_id', $campaignIds)
            ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as revenue')
            ->first();

        $facebook = \App\Models\FacebookAdsPerformanceData::whereIn('campaign_id', $campaignIds)
            ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as revenue')
            ->first();

        $microsoft = \App\Models\MicrosoftAdsPerformanceData::whereIn('campaign_id', $campaignIds)
            ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as revenue')
            ->first();

        $linkedin = \App\Models\LinkedInAdsPerformanceData::whereIn('campaign_id', $campaignIds)
            ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions, SUM(conversion_value) as revenue')
            ->first();

        $platforms = [];
        foreach (['google' => $google, 'facebook' => $facebook, 'microsoft' => $microsoft, 'linkedin' => $linkedin] as $name => $data) {
            if ($data && $data->impressions > 0) {
                $platforms[$name] = [
                    'impressions' => (int) $data->impressions,
                    'clicks' => (int) $data->clicks,
                    'cost' => round((float) $data->cost, 2),
                    'conversions' => (int) $data->conversions,
                    'revenue' => round((float) $data->revenue, 2),
                    'ctr' => $data->impressions > 0 ? round($data->clicks / $data->impressions, 4) : 0,
                    'cpc' => $data->clicks > 0 ? round($data->cost / $data->clicks, 2) : 0,
                    'roas' => $data->cost > 0 ? round($data->revenue / $data->cost, 2) : 0,
                ];
            }
        }

        $totalCost = array_sum(array_column($platforms, 'cost'));
        $totalRevenue = array_sum(array_column($platforms, 'revenue'));

        return [
            'platforms' => $platforms,
            'totals' => [
                'impressions' => array_sum(array_column($platforms, 'impressions')),
                'clicks' => array_sum(array_column($platforms, 'clicks')),
                'cost' => round($totalCost, 2),
                'conversions' => array_sum(array_column($platforms, 'conversions')),
                'revenue' => round($totalRevenue, 2),
                'roas' => $totalCost > 0 ? round($totalRevenue / $totalCost, 2) : 0,
            ],
        ];
    }
}
