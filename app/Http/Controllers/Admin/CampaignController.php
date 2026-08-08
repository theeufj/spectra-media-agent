<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Admin inspection and manual control of campaigns (deploy, pause, start, edit).
 *
 * Extracted from the former 1,000-line AdminController.
 */
class CampaignController extends Controller
{
    /**
     * Show detailed view of a campaign including strategies and collateral.
     */
    public function campaignShow(Campaign $campaign)
    {
        $campaign->load([
            'customer.users',
            'strategies' => function ($query) {
                $query->withCount(['adCopies', 'imageCollaterals', 'videoCollaterals']);
            },
        ]);

        // Get collateral for each strategy
        foreach ($campaign->strategies as $strategy) {
            $strategy->load(['adCopies', 'imageCollaterals', 'videoCollaterals']);
        }

        // Get recent activity logs for this campaign
        $activityLogs = \App\Models\ActivityLog::where('subject_type', \App\Models\Campaign::class)
            ->where('subject_id', $campaign->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return Inertia::render('Admin/CampaignDetail', [
            'campaign' => $campaign,
            'activityLogs' => $activityLogs,
        ]);
    }

    /**
     * Get performance data for a campaign (admin API endpoint).
     */
    public function campaignPerformance(Request $request, Campaign $campaign)
    {
        $campaign->load('customer');

        // Get date range from request
        $startDate = $request->input('start_date', now()->subDays(30)->format('Y-m-d'));
        $endDate = $request->input('end_date', now()->format('Y-m-d'));

        $customer = $campaign->customer;

        if (! $customer?->google_ads_customer_id || ! $campaign->google_ads_campaign_id) {
            return response()->json([
                'summary' => [
                    'impressions' => 0,
                    'clicks' => 0,
                    'cost' => 0,
                    'conversions' => 0,
                    'ctr' => 0,
                    'cpc' => 0,
                    'cpa' => 0,
                ],
                'daily_data' => [],
                'message' => 'No Google Ads account or campaign not deployed',
            ]);
        }

        try {
            // Management-account pattern: authenticate as the MCC against the
            // customer's sub-account. This previously read per-customer OAuth
            // tokens off a Connection row and passed them as constructor args.
            $service = new \App\Services\GoogleAds\CommonServices\GetCampaignPerformance($customer);

            $resourceName = $campaign->googleAdsResourceName();
            $metrics = $service($customer->cleanGoogleCustomerId(), $resourceName, 'LAST_30_DAYS');

            if (! $metrics) {
                return response()->json([
                    'summary' => [
                        'impressions' => 0,
                        'clicks' => 0,
                        'cost' => 0,
                        'conversions' => 0,
                        'ctr' => 0,
                        'cpc' => 0,
                        'cpa' => 0,
                    ],
                    'daily_data' => [],
                    'message' => 'No performance data available',
                ]);
            }

            return response()->json([
                'summary' => [
                    'impressions' => $metrics['impressions'],
                    'clicks' => $metrics['clicks'],
                    'cost' => $metrics['cost_micros'] / 1000000,
                    'conversions' => $metrics['conversions'],
                    'ctr' => round($metrics['ctr'] * 100, 2),
                    'cpc' => $metrics['average_cpc'] / 1000000,
                    'cpa' => $metrics['cost_per_conversion'] / 1000000,
                ],
                'daily_data' => [], // Could be expanded to include daily breakdown
            ]);

        } catch (\Exception $e) {
            Log::error("Admin failed to fetch performance for campaign {$campaign->id}: ".$e->getMessage());

            return response()->json([
                'summary' => [
                    'impressions' => 0,
                    'clicks' => 0,
                    'cost' => 0,
                    'conversions' => 0,
                    'ctr' => 0,
                    'cpc' => 0,
                    'cpa' => 0,
                ],
                'daily_data' => [],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pause a campaign in Google Ads.
     */
    /**
     * Admin-triggered deployment for campaigns pending manual setup.
     * Used when a customer had no Google Ads account ID at deploy time.
     */
    public function adminDeployCampaign(Campaign $campaign)
    {
        $customer = $campaign->customer;

        // Verify the account ID is now attached before we try to deploy
        $hasGoogleStrategy = $campaign->strategies()
            ->whereNotNull('signed_off_at')
            ->where(fn ($q) => $q->where('platform', 'like', '%google%')->orWhere('platform', 'like', '%Google%'))
            ->exists();

        if ($hasGoogleStrategy && empty($customer->google_ads_customer_id)) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'This customer still has no Google Ads account ID. Attach one above before deploying.',
            ]);
        }

        // Reset deployment_status so the idempotency guard allows deployment
        $campaign->strategies()
            ->whereNotNull('signed_off_at')
            ->update(['deployment_status' => null]);

        \App\Jobs\DeployCampaign::dispatch($campaign, useAgents: true);

        Log::info("Admin triggered deployment for campaign {$campaign->id}", [
            'admin_user_id' => auth()->id(),
            'customer_id' => $customer->id,
        ]);

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => "Deployment dispatched for \"{$campaign->name}\". It will go live shortly.",
        ]);
    }

    public function pauseCampaign(Campaign $campaign)
    {
        try {
            if (! $campaign->google_ads_campaign_id) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'Campaign has no Google Ads campaign ID.',
                ]);
            }

            $customer = $campaign->customer;

            if (! $customer?->google_ads_customer_id) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'Customer has no Google Ads account.',
                ]);
            }

            // Management-account pattern: authenticate as the platform MCC and
            // address the customer's sub-account, rather than per-customer OAuth
            // tokens (which this method previously read off a Connection row).
            $service = new UpdateCampaignStatus($customer);

            $resourceName = $campaign->googleAdsResourceName();
            $result = $service->pause($customer->cleanGoogleCustomerId(), $resourceName);

            if ($result['success']) {
                $campaign->update(['platform_status' => 'PAUSED']);
                Log::info("Admin paused campaign {$campaign->id} (Google: {$campaign->google_ads_campaign_id})");

                return redirect()->back()->with('flash', [
                    'type' => 'success',
                    'message' => 'Campaign paused successfully.',
                ]);
            }

            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Failed to pause campaign: '.($result['error'] ?? 'Unknown error'),
            ]);

        } catch (\Exception $e) {
            Log::error("Admin failed to pause campaign {$campaign->id}: ".$e->getMessage());

            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Error pausing campaign: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Start/enable a campaign in Google Ads.
     */
    public function startCampaign(Campaign $campaign)
    {
        try {
            if (! $campaign->google_ads_campaign_id) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'Campaign has no Google Ads campaign ID.',
                ]);
            }

            $customer = $campaign->customer;

            if (! $customer?->google_ads_customer_id) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'Customer has no Google Ads account.',
                ]);
            }

            // Management-account pattern — see pauseCampaign().
            $service = new UpdateCampaignStatus($customer);

            $resourceName = $campaign->googleAdsResourceName();
            $result = $service->enable($customer->cleanGoogleCustomerId(), $resourceName);

            if ($result['success']) {
                $campaign->update(['platform_status' => 'ENABLED']);
                Log::info("Admin started campaign {$campaign->id} (Google: {$campaign->google_ads_campaign_id})");

                return redirect()->back()->with('flash', [
                    'type' => 'success',
                    'message' => 'Campaign started successfully.',
                ]);
            }

            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Failed to start campaign: '.($result['error'] ?? 'Unknown error'),
            ]);

        } catch (\Exception $e) {
            Log::error("Admin failed to start campaign {$campaign->id}: ".$e->getMessage());

            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Error starting campaign: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Update campaign settings (budget, dates, etc.)
     */
    public function updateCampaign(Request $request, Campaign $campaign)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'daily_budget' => 'sometimes|numeric|min:1',
            'total_budget' => 'sometimes|numeric|min:1',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
        ]);

        $campaign->update($validated);

        Log::info("Admin updated campaign {$campaign->id}", $validated);

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Campaign updated successfully.',
        ]);
    }
}
