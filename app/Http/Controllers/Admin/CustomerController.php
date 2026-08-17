<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdSpendTransaction;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;
use App\Services\ActivityLogger;
use App\Services\Customers\DeactivateCustomerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Admin views of customer accounts and their per-platform ad account identifiers.
 *
 * Extracted from the former 1,000-line AdminController.
 */
class CustomerController extends Controller
{
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

        $credit = $customer->adSpendCredit;
        $campaignIds = $customer->campaigns()->pluck('id');

        $googleSpend = GoogleAdsPerformanceData::whereIn('campaign_id', $campaignIds)->sum('cost');
        $facebookSpend = FacebookAdsPerformanceData::whereIn('campaign_id', $campaignIds)->sum('cost');
        $totalActualSpend = round($googleSpend + $facebookSpend, 2);
        $totalDebited = $credit ? round($credit->transactions()->whereIn('type', [AdSpendTransaction::TYPE_DEDUCTION, AdSpendTransaction::TYPE_ADJUSTMENT])->sum('amount'), 2) : 0;

        return Inertia::render('Admin/CustomerDetail', [
            'customer' => $customer,
            'bm_configured' => app(\App\Services\FacebookAds\BusinessManagerService::class)->isConfigured(),
            'adSpendCredit' => $credit ? [
                'current_balance' => (float) $credit->current_balance,
                'initial_credit_amount' => (float) $credit->initial_credit_amount,
                'status' => $credit->status,
                'payment_status' => $credit->payment_status,
                'total_actual_spend' => $totalActualSpend,
                'total_debited' => $totalDebited,
                'unreconciled' => round($totalActualSpend - $totalDebited, 2),
            ] : null,
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
            // Mark as BM-managed whenever an admin assigns an account ID — this is the
            // gate that allows FacebookAdsExecutionAgent to deploy campaigns.
            'facebook_bm_owned' => ! empty($validated['facebook_ads_account_id']),
        ];

        if (! empty($validated['facebook_page_url'])) {
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
        if ($customerId && ! $managerId) {
            $managerId = config('googleads.mcc_customer_id');
        }

        $customer->update([
            'google_ads_customer_id' => $customerId,
            'google_ads_manager_customer_id' => $managerId,
        ]);

        // Trigger conversion tracking setup when a Google Ads account is connected for the first time
        if ($customerId && ! $customer->conversion_action_id) {
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
     * Delete a customer, after stopping everything they are spending.
     *
     * The order matters more than the deletion does. This used to be a hard
     * delete with nothing behind it, so the campaigns on every platform kept
     * running and kept charging while the records identifying them were
     * destroyed — the action most likely to be taken *because* someone wanted
     * the spending to stop was the one that removed the means to stop it.
     *
     * A customer whose campaigns could not all be paused is not deleted. Being
     * told why is more useful than a tidy customer list and an unexplained bill.
     */
    public function deleteCustomer(Request $request, Customer $customer, DeactivateCustomerService $deactivator)
    {
        // Typing the name is the confirmation. A UI dialog is advice; this is
        // the part an accidental request cannot satisfy.
        if ($request->input('confirm_name') !== $customer->name) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Type the customer name exactly to confirm deletion.',
            ]);
        }

        $result = $deactivator->pauseAllCampaigns($customer);

        if ($result['failed'] > 0) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => "Not deleted — {$result['failed']} campaign(s) could not be paused and would keep spending: "
                    .implode(' | ', array_slice($result['errors'], 0, 3)),
            ]);
        }

        ActivityLogger::customer('deleted', $customer);

        Log::warning('Admin deleted customer', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'admin_id' => $request->user()?->id,
            'campaigns_paused' => $result['paused'],
        ]);

        // Soft delete — the trail survives, and the customer can be restored.
        $customer->delete();

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => "Deleted \"{$customer->name}\" after pausing {$result['paused']} campaign(s).",
        ]);
    }

    /**
     * Bring back a soft-deleted customer.
     *
     * Their campaigns stay paused: restoring the record should not restart
     * spending on its own.
     */
    public function restoreCustomer(int $customerId)
    {
        $customer = Customer::withTrashed()->findOrFail($customerId);
        $customer->restore();

        ActivityLogger::customer('restored', $customer);

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => "Restored \"{$customer->name}\". Their campaigns remain paused.",
        ]);
    }
}
