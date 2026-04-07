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

        return Inertia::render('Admin/CampaignDetail', [
            'campaign' => $campaign,
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

            $resourceName = "customers/{$connection->platform_user_id}/campaigns/{$campaign->google_ads_campaign_id}";
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

            $resourceName = "customers/{$connection->platform_user_id}/campaigns/{$campaign->google_ads_campaign_id}";
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

            $resourceName = "customers/{$connection->platform_user_id}/campaigns/{$campaign->google_ads_campaign_id}";
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

    public function settingsIndex()
    {
        $settings = Setting::all();
        
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
        ]);

        Setting::set('deployment_enabled', $request->deployment_enabled, 'boolean');
        
        // Store campaign testing mode in database
        if ($request->has('campaign_testing_mode')) {
            Setting::set('campaign_testing_mode', $request->campaign_testing_mode, 'boolean');
        }

        if ($request->has('managed_billing_enabled')) {
            Setting::set('managed_billing_enabled', $request->managed_billing_enabled, 'boolean');
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
