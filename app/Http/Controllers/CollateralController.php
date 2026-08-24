<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\HarvestedAsset;
use App\Models\ImageCollateral;
use App\Models\Setting;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\CreativeQuotaService;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CollateralController extends Controller
{
    /**
     * Display the collateral generation page for a specific campaign strategy.
     *
     * @param  Campaign  $campaign  The campaign model instance.
     * @param  Strategy  $strategy  The strategy model instance.
     * @return \Inertia\Response
     */
    public function show(Campaign $campaign, Strategy $strategy)
    {
        // Ensure the campaign belongs to a customer that the authenticated user is part of.
        $user = Auth::user();
        $this->authorize('view', $campaign);

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
        $imageCollaterals = ImageCollateral::forStrategy($strategy)->where('is_active', true)->get();

        // Find all active video collaterals for the current strategy
        $videoCollaterals = VideoCollateral::forStrategy($strategy)->where('is_active', true)->get();

        // Get the customer's ad spend credit status
        $customer = $campaign->customer;
        $adSpendCredit = $customer->adSpendCredit;

        // Harvested assets count for this customer
        $harvestedAssetCount = HarvestedAsset::where('customer_id', $customer->id)
            ->whereIn('classification', ['product', 'lifestyle', 'team'])
            ->whereIn('status', ['classified', 'processed'])
            ->count();

        return Inertia::render('Campaigns/Collateral', [
            'campaign' => $campaign,
            'currentStrategy' => $strategy,
            'allStrategies' => $allStrategies,
            'adCopy' => $adCopy,
            'imageCollaterals' => $imageCollaterals,
            'videoCollaterals' => $videoCollaterals,
            'collateralErrors' => $this->liveCollateralErrors($strategy, $adCopy, $imageCollaterals, $videoCollaterals),
            // Teammate-aware: a member on a company plan has access through
            // the owner's subscription.
            'hasActiveSubscription' => $user->hasSubscriptionAccess($campaign->customer),
            'hasPaymentMethod' => $user->hasDefaultPaymentMethod(),
            'deploymentEnabled' => Setting::get('deployment_enabled', true),
            // Mirrors DeployCampaign's own rule: self-funded accounts are
            // billed by the platform directly, so never show them the
            // prepay-funding modal (it double-billed them).
            'managedBillingEnabled' => Setting::get('managed_billing_enabled', true) && ! $campaign->customer->isSelfFundedAds(),
            'creativeUsage' => app(CreativeQuotaService::class)->getUsageSummary($user),
            'adSpendCredit' => $adSpendCredit ? [
                'id' => $adSpendCredit->id,
                'status' => $adSpendCredit->status,
                'current_balance' => $adSpendCredit->current_balance,
                'payment_status' => $adSpendCredit->payment_status,
            ] : null,
            'harvestedAssetCount' => $harvestedAssetCount,
        ]);
    }

    /**
     * getCollateralJson returns the latest collateral data as JSON for polling.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCollateralJson(Strategy $strategy)
    {
        // Ensure the user is authorized to view this collateral.
        $user = Auth::user();
        $this->authorize('view', $strategy->campaign);

        $strategy->load(['adCopies', 'imageCollaterals', 'videoCollaterals']);

        $adCopy = $strategy->adCopies->where('platform', $strategy->platform)->first();
        $imageCollaterals = ImageCollateral::forStrategy($strategy)->where('is_active', true)->get();
        $videoCollaterals = VideoCollateral::forStrategy($strategy)->where('is_active', true)->get();

        return response()->json([
            'adCopy' => $adCopy,
            'imageCollaterals' => $imageCollaterals,
            'videoCollaterals' => $videoCollaterals,
            'collateralErrors' => $this->liveCollateralErrors($strategy, $adCopy, $imageCollaterals, $videoCollaterals),
        ]);
    }

    /**
     * Stored generation failures that are still true — i.e. the collateral in
     * question is actually missing. Errors persist from old runs (only a
     * later success of that exact generator clears them), so an 11-day-old
     * "ad copy failed" banner was greeting users whose ad copy had long since
     * generated fine.
     */
    private function liveCollateralErrors(Strategy $strategy, $adCopy, $imageCollaterals, $videoCollaterals): array
    {
        return collect($strategy->collateral_errors ?? [])
            ->reject(fn ($message, $key) => match (true) {
                str_contains($key, 'ad_copy') => $adCopy !== null,
                str_contains($key, 'image') => $imageCollaterals->isNotEmpty(),
                str_contains($key, 'video') => $videoCollaterals->isNotEmpty(),
                default => false,
            })
            ->all();
    }
}
