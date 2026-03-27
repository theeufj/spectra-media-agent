<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\FacebookAds\BusinessManagerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
                'id',
                'name',
                'facebook_ads_account_id',
                'facebook_bm_owned',
                'facebook_page_id',
                'facebook_page_name',
            ]),
            'bm_configured' => $this->bmService->isConfigured(),
        ]);
    }

    /**
     * Provision a platform-owned Facebook ad account for the customer (Path A).
     *
     * The platform's Business Manager creates the account—the client never
     * needs to grant OAuth permissions.
     */
    public function provision(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'currency' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'string', 'max:60'],
        ]);

        $result = $this->bmService->provisionAdAccount(
            $customer,
            $validated['currency'] ?? 'USD',
            $validated['timezone'] ?? 'America/New_York'
        );

        if (!$result['success']) {
            return back()->with('error', 'Failed to create ad account: ' . $result['error']);
        }

        return back()->with([
            'success'  => 'Facebook ad account created! Account ID: act_' . $result['account_id'],
            'customer' => $customer->fresh()->only([
                'id', 'name', 'facebook_ads_account_id', 'facebook_bm_owned',
                'facebook_page_id', 'facebook_page_name',
            ]),
        ]);
    }
}
