<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\FacebookAds\BusinessManagerService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FacebookAdAccountController extends Controller
{
    public function __construct(protected BusinessManagerService $bmService) {}

    /**
     * Show the Facebook ad account setup page for a customer.
     */
    public function show(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        return Inertia::render('Customers/Facebook/AdAccountSetup', [
            'customer'      => $customer->only([
                'id', 'name', 'facebook_ads_account_id', 'facebook_bm_owned',
                'facebook_page_id', 'facebook_page_name',
            ]),
            'bm_configured' => $this->bmService->isConfigured(),
        ]);
    }

    /**
     * Assign a manually-created BM ad account to the customer (Path A).
     *
     * The platform admin creates the ad account in the Business Manager UI,
     * assigns the System User as Admin, then enters the account ID here.
     * The System User token is then used for all ad operations — no client
     * OAuth required.
     */
    public function assign(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'ad_account_id' => ['required', 'string', 'regex:/^\d+$/'],
        ], [
            'ad_account_id.regex' => 'Enter the numeric account ID only (e.g. 123456789), without the "act_" prefix.',
        ]);

        $result = $this->bmService->assignAdAccount($customer, $validated['ad_account_id']);

        if (!$result['success']) {
            return back()->with('error', 'Could not link ad account: ' . $result['error']);
        }

        return back()->with([
            'success'  => 'Facebook ad account linked! Account: act_' . $result['account_id'] . ($result['name'] ? ' (' . $result['name'] . ')' : ''),
            'customer' => $customer->fresh()->only([
                'id', 'name', 'facebook_ads_account_id', 'facebook_bm_owned',
                'facebook_page_id', 'facebook_page_name',
            ]),
        ]);
    }

    /**
     * Verify the System User still has access to the customer's ad account.
     */
    public function verify(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        if (!$customer->facebook_ads_account_id) {
            return back()->with('error', 'No ad account linked yet.');
        }

        $result = $this->bmService->verifyAdAccountAccess($customer->facebook_ads_account_id);

        if (!$result['success']) {
            return back()->with('error', 'Access check failed: ' . $result['error']);
        }

        return back()->with('success', 'System User has access to act_' . $customer->facebook_ads_account_id . ' (' . ($result['name'] ?? 'unknown') . ')');
    }

}
