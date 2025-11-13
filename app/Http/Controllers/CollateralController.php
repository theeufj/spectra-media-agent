<?php

namespace App\Http\Controllers;

use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Strategy;
use Illuminate\Http\Request;

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
        // Ensure the campaign belongs to the authenticated user.
        if ($campaign->user_id !== Auth::id()) {
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
        $strategy->load(['adCopies', 'imageCollaterals']);

        // Get all strategies for the campaign to build the tab navigation
        $allStrategies = $campaign->strategies()->get();

        // Find the specific ad copy for the current strategy and platform
        $adCopy = $strategy->adCopies->where('platform', $strategy->platform)->first();

        // Find all image collaterals for the current strategy
        $imageCollaterals = $strategy->imageCollaterals;

        return Inertia::render('Campaigns/Collateral', [
            'campaign' => $campaign,
            'currentStrategy' => $strategy,
            'allStrategies' => $allStrategies,
            'adCopy' => $adCopy,
            'imageCollaterals' => $imageCollaterals,
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
        if ($strategy->campaign->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        $strategy->load(['adCopies', 'imageCollaterals']);
        
        return response()->json([
            'adCopy' => $strategy->adCopies->where('platform', $strategy->platform)->first(),
            'imageCollaterals' => $strategy->imageCollaterals,
        ]);
    }
}
