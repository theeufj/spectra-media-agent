<?php

namespace App\Http\Controllers;

use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Strategy;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CollateralController extends Controller
{
    /**
     * Display the collateral generation page for a specific campaign strategy.
     *
     * @param Campaign $campaign The campaign model instance.
     * @param Strategy $strategy The strategy model instance.
     * @return \Inertia\Response
     */
    public function show(Campaign $campaign, Strategy $strategy)
    {
        // Ensure the campaign belongs to a customer that the authenticated user is part of.
        $user = Auth::user();
        if (!$user->customers()->where('customers.id', $campaign->customer_id)->exists()) {
            abort(403, 'Unauthorized action.');
        }

        // Ensure the strategy belongs to the campaign.
        if ($strategy->campaign_id !== $campaign->id) {
            abort(403, 'Strategy does not belong to this campaign.');
        }

        // Check if the strategy has been signed off.
        if (is_null($strategy->signed_off_at)) {
            return redirect()->route('campaigns.show', $campaign)->with('error', 'Strategy must be signed off before generating collateral.');
        }

        // Eager load the ad copy and image collaterals for the given strategy
        $strategy->load(['adCopies', 'imageCollaterals', 'videoCollaterals']);

        // Get all strategies for the campaign to build the tab navigation, including collateral counts
        $allStrategies = $campaign->strategies()->withCount(['adCopies', 'imageCollaterals', 'videoCollaterals'])->get();

        // Find the specific ad copy for the current strategy and platform
        $adCopy = $strategy->adCopies->where('platform', $strategy->platform)->first();
        
        // Find all active image collaterals for the current strategy
        $imageCollaterals = $strategy->imageCollaterals()->where('is_active', true)->get();

        // Find all active video collaterals for the current strategy
        $videoCollaterals = $strategy->videoCollaterals()->where('is_active', true)->get();

        return Inertia::render('Campaigns/Collateral', [
            'campaign' => $campaign,
            'currentStrategy' => $strategy,
            'allStrategies' => $allStrategies,
            'adCopy' => $adCopy,
            'imageCollaterals' => $imageCollaterals,
            'videoCollaterals' => $videoCollaterals,
            'hasActiveSubscription' => $user->subscribed('default') || $user->hasDefaultPaymentMethod(),
            'deploymentEnabled' => Setting::get('deployment_enabled', true),
        ]);
    }

    /**
     * getCollateralJson returns the latest collateral data as JSON for polling.
     *
     * @param Strategy $strategy
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCollateralJson(Strategy $strategy)
    {
        // Ensure the user is authorized to view this collateral.
        $user = Auth::user();
        if (!$user->customers()->where('customers.id', $strategy->campaign->customer_id)->exists()) {
            abort(403, 'Unauthorized action.');
        }

        $strategy->load(['adCopies', 'imageCollaterals', 'videoCollaterals']);
        
        return response()->json([
            'adCopy' => $strategy->adCopies->where('platform', $strategy->platform)->first(),
            'imageCollaterals' => $strategy->imageCollaterals()->where('is_active', true)->get(),
            'videoCollaterals' => $strategy->videoCollaterals()->where('is_active', true)->get(),
        ]);
    }
}
