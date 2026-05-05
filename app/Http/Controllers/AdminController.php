<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Models\Customer;
use App\Models\Campaign;
use App\Models\Strategy;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function index()
    {
        return redirect()->route('admin.users.index');
    }

    public function usersIndex()
    {
        $users = User::with(['roles', 'assignedPlan'])->get();
        $plans = \App\Models\Plan::active()->ordered()->get();
        return Inertia::render('Admin/Users', [
            'users' => $users,
            'plans' => $plans,
        ]);
    }

    public function assignPlan(Request $request, User $user)
    {
        $validated = $request->validate([
            'plan_id' => 'nullable|exists:plans,id',
        ]);

        $user->assigned_plan_id = $validated['plan_id'];
        // When admin promotes a user to a plan, mark them as active so they pass subscription gates.
        // When a plan is removed, revert to guest.
        $user->subscription_status = $validated['plan_id'] ? 'active' : 'guest';
        $user->save();

        $planName = $validated['plan_id'] ? \App\Models\Plan::find($validated['plan_id'])->name : 'None';
        Log::info('Admin assigned plan to user', [
            'user_id' => $user->id,
            'plan_id' => $validated['plan_id'],
            'plan_name' => $planName,
        ]);

        return redirect()->back()->with('success', "Plan '{$planName}' assigned to {$user->name}.");
    }

    public function customersIndex()
    {
        $customers = Customer::with(['users.assignedPlan', 'campaigns'])->withCount('campaigns')->get();
        $plans = \App\Models\Plan::active()->ordered()->get();
        return Inertia::render('Admin/Customers', [
            'customers' => $customers,
            'plans' => $plans,
        ]);
    }

    /**
     * Show detailed view of a customer including campaigns, strategies, and collateral.
     */
    public function customerShow(Customer $customer)
    {
        $customer->load([
            'users',
            'campaigns' => function ($query) {
                $query->with(['strategies' => function ($q) {
                    $q->withCount(['adCopies', 'imageCollaterals', 'videoCollaterals']);
                }]);
            },
        ]);

        return Inertia::render('Admin/CustomerDetail', [
            'customer' => $customer,
            'bm_configured' => app(\App\Services\FacebookAds\BusinessManagerService::class)->isConfigured(),
        ]);
    }

    /**
     * Update the Facebook Ad Account ID for a customer (admin only).
     */
    public function updateCustomerFacebook(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'facebook_ads_account_id' => 'nullable|string|max:50',
            'facebook_page_url' => 'nullable|string|max:500',
        ]);

        $updates = [
            'facebook_ads_account_id' => $validated['facebook_ads_account_id'] ?: null,
        ];

        if (!empty($validated['facebook_page_url'])) {
            $parsed = Customer::parseFacebookPageUrl($validated['facebook_page_url']);
            if ($parsed) {
                $updates['facebook_page_id'] = $parsed['page_id'];
                if ($parsed['page_name']) {
                    $updates['facebook_page_name'] = $parsed['page_name'];
                }
            }
        }

        $customer->update($updates);

        Log::info('Admin updated Facebook settings', [
            'customer_id' => $customer->id,
            'facebook_ads_account_id' => $customer->facebook_ads_account_id,
            'facebook_page_id' => $customer->facebook_page_id,
        ]);

        return redirect()->back()->with('success', 'Facebook Ad Account ID updated.');
    }

    /**
     * Update the Microsoft Ads IDs for a customer (admin only).
     */
    public function updateCustomerMicrosoft(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'microsoft_ads_customer_id' => 'nullable|string|max:50',
            'microsoft_ads_account_id' => 'nullable|string|max:50',
        ]);

        $customer->update([
            'microsoft_ads_customer_id' => $validated['microsoft_ads_customer_id'] ?: null,
            'microsoft_ads_account_id' => $validated['microsoft_ads_account_id'] ?: null,
        ]);

        Log::info('Admin updated Microsoft Ads settings', [
            'customer_id' => $customer->id,
            'microsoft_ads_customer_id' => $customer->microsoft_ads_customer_id,
            'microsoft_ads_account_id' => $customer->microsoft_ads_account_id,
        ]);

        return redirect()->back()->with('success', 'Microsoft Ads account updated.');
    }

    /**
     * Update the Google Ads IDs for a customer (admin only).
     * Used when Standard Access is pending and sub-accounts are created manually in Google Ads UI.
     */
    public function updateCustomerGoogle(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'google_ads_customer_id' => 'nullable|string|max:50',
            'google_ads_manager_customer_id' => 'nullable|string|max:50',
        ]);

        // Strip dashes (Google Ads UI shows xxx-xxx-xxxx but API uses digits only)
        $customerId = $validated['google_ads_customer_id']
            ? preg_replace('/[^0-9]/', '', $validated['google_ads_customer_id'])
            : null;
        $managerId = $validated['google_ads_manager_customer_id']
            ? preg_replace('/[^0-9]/', '', $validated['google_ads_manager_customer_id'])
            : null;

        // Default manager to active MCC if not provided but customer ID is set
        if ($customerId && !$managerId) {
            $managerId = config('googleads.mcc_customer_id');
        }

        $customer->update([
            'google_ads_customer_id' => $customerId,
            'google_ads_manager_customer_id' => $managerId,
        ]);

        // Trigger conversion tracking setup when a Google Ads account is connected for the first time
        if ($customerId && !$customer->conversion_action_id) {
            \App\Jobs\SetupConversionTracking::dispatch($customer)->delay(now()->addSeconds(10));
        }

        Log::info('Admin updated Google Ads settings', [
            'customer_id' => $customer->id,
            'google_ads_customer_id' => $customer->google_ads_customer_id,
            'google_ads_manager_customer_id' => $customer->google_ads_manager_customer_id,
        ]);

        return redirect()->back()->with('success', 'Google Ads account updated.');
    }

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
     * Pause a campaign in Google Ads.
     */
    public function pauseCampaign(Campaign $campaign)
    {
        try {
            if (!$campaign->google_ads_campaign_id) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'Campaign has no Google Ads campaign ID.'
                ]);
            }

            $customer = $campaign->customer;
            $connection = $customer->users()->first()?->connections()
                ->where('platform', 'google_ads')
                ->first();

            if (!$connection) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'No Google Ads connection found for this customer.'
                ]);
            }

            $service = new UpdateCampaignStatus(
                $connection->platform_user_id,
                $connection->access_token,
                $connection->refresh_token
            );

            $resourceName = $campaign->googleAdsResourceName();
            $result = $service->pause($resourceName);

            if ($result['success']) {
                $campaign->update(['platform_status' => 'PAUSED']);
                Log::info("Admin paused campaign {$campaign->id} (Google: {$campaign->google_ads_campaign_id})");
                
                return redirect()->back()->with('flash', [
                    'type' => 'success',
                    'message' => 'Campaign paused successfully.'
                ]);
            }

            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Failed to pause campaign: ' . ($result['error'] ?? 'Unknown error')
            ]);

        } catch (\Exception $e) {
            Log::error("Admin failed to pause campaign {$campaign->id}: " . $e->getMessage());
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Error pausing campaign: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Start/enable a campaign in Google Ads.
     */
    public function startCampaign(Campaign $campaign)
    {
        try {
            if (!$campaign->google_ads_campaign_id) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'Campaign has no Google Ads campaign ID.'
                ]);
            }

            $customer = $campaign->customer;
            $connection = $customer->users()->first()?->connections()
                ->where('platform', 'google_ads')
                ->first();

            if (!$connection) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'No Google Ads connection found for this customer.'
                ]);
            }

            $service = new UpdateCampaignStatus(
                $connection->platform_user_id,
                $connection->access_token,
                $connection->refresh_token
            );

            $resourceName = $campaign->googleAdsResourceName();
            $result = $service->enable($resourceName);

            if ($result['success']) {
                $campaign->update(['platform_status' => 'ENABLED']);
                Log::info("Admin started campaign {$campaign->id} (Google: {$campaign->google_ads_campaign_id})");
                
                return redirect()->back()->with('flash', [
                    'type' => 'success',
                    'message' => 'Campaign started successfully.'
                ]);
            }

            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Failed to start campaign: ' . ($result['error'] ?? 'Unknown error')
            ]);

        } catch (\Exception $e) {
            Log::error("Admin failed to start campaign {$campaign->id}: " . $e->getMessage());
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Error starting campaign: ' . $e->getMessage()
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
            'message' => 'Campaign updated successfully.'
        ]);
    }

    /**
     * Show performance dashboard for a customer (admin view).
     */
    public function customerDashboard(Customer $customer)
    {
        $customer->load('users');
        $campaigns = $customer->campaigns()->orderBy('created_at', 'desc')->get();

        return Inertia::render('Admin/CustomerDashboard', [
            'customer' => $customer,
            'campaigns' => $campaigns,
            'defaultCampaign' => $campaigns->first(),
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

        // Get Google Ads connection
        $connection = $campaign->customer->users()->first()?->connections()
            ->where('platform', 'google_ads')
            ->first();

        if (!$connection || !$campaign->google_ads_campaign_id) {
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
                'message' => 'No Google Ads connection or campaign not deployed',
            ]);
        }

        try {
            $service = new \App\Services\GoogleAds\CommonServices\GetCampaignPerformance(
                $connection->platform_user_id,
                $connection->access_token,
                $connection->refresh_token
            );

            $resourceName = $campaign->googleAdsResourceName();
            $metrics = $service($connection->platform_user_id, $resourceName, 'LAST_30_DAYS');

            if (!$metrics) {
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
            Log::error("Admin failed to fetch performance for campaign {$campaign->id}: " . $e->getMessage());
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

    public function notificationsIndex()
    {
        return Inertia::render('Admin/Notifications');
    }

    public function conversionTrackingIndex()
    {
        $awId = config('conversions.aw_id', 'AW-16797144138');
        $events = config('conversions.events', []);

        $actions = collect($events)->map(function ($def, $key) use ($awId) {
            $label        = Setting::get("conversion_label.{$key}", $def['label'] ?? null);
            $resourceName = Setting::get("conversion_resource_name.{$key}");
            $isServer     = ($def['mode'] ?? 'client') === 'server';
            return [
                'key'           => $key,
                'name'          => 'Spectra — ' . ucfirst(str_replace('_', ' ', $key)),
                'label'         => $label,
                'send_to'       => $label ? "{$awId}/{$label}" : null,
                'resource_name' => $resourceName,
                'mode'          => $def['mode'] ?? 'client',
                'value'         => $def['value'] ?? null,
                'currency'      => $def['currency'] ?? 'USD',
                'provisioned'   => $isServer ? $resourceName !== null : $label !== null,
            ];
        })->values();

        // Counts from the local AttributionConversion table (grouped by type)
        $attributionCounts = \App\Models\AttributionConversion::query()
            ->selectRaw('conversion_type, COUNT(*) as total, SUM(conversion_value) as value_sum')
            ->groupBy('conversion_type')
            ->get()
            ->keyBy('conversion_type');

        $recentSignups7d  = \App\Models\User::where('created_at', '>=', now()->subDays(7))->count();
        $recentSignups30d = \App\Models\User::where('created_at', '>=', now()->subDays(30))->count();

        // Per-event totals and recent log from our own conversion event table
        $eventTotals = \App\Models\SpectraConversionEvent::query()
            ->selectRaw('event, COUNT(*) as total, SUM(value) as value_sum, MAX(created_at) as last_fired')
            ->groupBy('event')
            ->get()
            ->keyBy('event');

        $recentEvents = \App\Models\SpectraConversionEvent::query()
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'event', 'user_id', 'gclid', 'fbclid', 'mode', 'value', 'uploaded_to_google', 'created_at']);

        // Platform-level signal counts — how many of our own signups came via each ad platform
        $signupsByPlatform = \App\Models\User::query()
            ->selectRaw("
                COUNT(*) FILTER (WHERE gclid IS NOT NULL) AS via_google,
                COUNT(*) FILTER (WHERE fbclid IS NOT NULL) AS via_facebook,
                COUNT(*) FILTER (WHERE msclid IS NOT NULL) AS via_microsoft
            ")
            ->where('created_at', '>=', now()->subDays(30))
            ->first();

        return Inertia::render('Admin/ConversionTracking', [
            'aw_id'              => $awId,
            'actions'            => $actions,
            'attribution'        => $attributionCounts,
            'signups_7d'         => $recentSignups7d,
            'signups_30d'        => $recentSignups30d,
            'customer_id'        => config('conversions.google_ads_customer_id'),
            'event_totals'       => $eventTotals,
            'recent_events'      => $recentEvents,
            'signups_by_platform' => [
                'google'    => (int) ($signupsByPlatform->via_google ?? 0),
                'facebook'  => (int) ($signupsByPlatform->via_facebook ?? 0),
                'microsoft' => (int) ($signupsByPlatform->via_microsoft ?? 0),
            ],
        ]);
    }

    public function settingsIndex()
    {
        $settings = Setting::all();
        
        // Ensure boost pack defaults exist for the UI
        $boostDefaults = [
            'creative_boost_price_cents' => ['value' => 2900, 'type' => 'integer', 'description' => 'Creative Boost Pack price in cents'],
            'creative_boost_image_generations' => ['value' => 25, 'type' => 'integer', 'description' => 'Image generations per boost pack'],
            'creative_boost_video_generations' => ['value' => 5, 'type' => 'integer', 'description' => 'Video generations per boost pack'],
            'creative_boost_refinements' => ['value' => 25, 'type' => 'integer', 'description' => 'Refinements per boost pack'],
        ];

        $existingKeys = $settings->pluck('key')->toArray();
        foreach ($boostDefaults as $key => $meta) {
            if (!in_array($key, $existingKeys)) {
                $settings->push(new Setting(['key' => $key, 'value' => (string) $meta['value'], 'type' => $meta['type'], 'description' => $meta['description']]));
            }
        }

        return Inertia::render('Admin/Settings', [
            'settings' => $settings,
            'campaignModeDescription' => \App\Services\CampaignStatusHelper::getModeDescription(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'deployment_enabled' => 'required|boolean',
            'campaign_testing_mode' => 'sometimes|boolean',
            'managed_billing_enabled' => 'sometimes|boolean',
            'creative_boost_price_cents' => 'sometimes|integer|min:100',
            'creative_boost_image_generations' => 'sometimes|integer|min:0',
            'creative_boost_video_generations' => 'sometimes|integer|min:0',
            'creative_boost_refinements' => 'sometimes|integer|min:0',
        ]);

        Setting::set('deployment_enabled', $request->deployment_enabled, 'boolean');
        
        // Store campaign testing mode in database
        if ($request->has('campaign_testing_mode')) {
            Setting::set('campaign_testing_mode', $request->campaign_testing_mode, 'boolean');
        }

        if ($request->has('managed_billing_enabled')) {
            Setting::set('managed_billing_enabled', $request->managed_billing_enabled, 'boolean');
        }

        // Creative Boost Pack settings
        foreach (['creative_boost_price_cents', 'creative_boost_image_generations', 'creative_boost_video_generations', 'creative_boost_refinements'] as $key) {
            if ($request->has($key)) {
                Setting::set($key, $request->integer($key), 'integer');
            }
        }

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Settings updated successfully.'
        ]);
    }

    public function promoteToAdmin(User $user)
    {
        $adminRole = Role::where('name', 'admin')->first();
        $user->roles()->syncWithoutDetaching([$adminRole->id]);
        
        ActivityLogger::userPromoted($user);

        return redirect()->back();
    }

    public function deleteCustomer(Customer $customer)
    {
        ActivityLogger::customer('deleted', $customer);
        $customer->delete();

        return redirect()->back();
    }

    public function deleteUser(User $user)
    {
        if ($user->hasRole('admin')) {
            return redirect()->back()->with('error', 'Cannot delete an admin user.');
        }

        ActivityLogger::log('user_deleted', "Deleted user: {$user->name} ({$user->email})");
        $user->roles()->detach();
        $user->customers()->detach();
        $user->delete();

        return redirect()->back()->with('success', "User '{$user->name}' has been deleted.");
    }

    public function banUser(User $user)
    {
        $user->update(['banned_at' => now()]);
        ActivityLogger::userBanned($user);

        return redirect()->back();
    }

    public function unbanUser(User $user)
    {
        $user->update(['banned_at' => null]);
        ActivityLogger::userUnbanned($user);

        return redirect()->back();
    }

    public function sendNotification(Request $request)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        $users = User::all();

        foreach ($users as $user) {
            Mail::to($user->email)->send(new \App\Mail\AdminNotification($user, $request->subject, $request->body));
        }

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Notification sent to all users successfully.'
        ]);
    }
}
