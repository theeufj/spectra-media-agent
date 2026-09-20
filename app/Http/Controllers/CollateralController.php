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
     * What GenerateStrategyCollateral dispatches. Kept in step with it by the
     * test that pins both.
     */
    /**
     * How long after sign-off the image set is still plausibly arriving.
     *
     * Bounds the "more is coming" state so a set that stopped early — a format
     * skipped because its aspect failed to generate, which is now normal —
     * does not leave a spinner turning for ever. Generation takes two to three
     * minutes; ten is generous and still finite.
     */
    private const IMAGE_GENERATION_WINDOW_MINUTES = 10;

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
            /*
               A one-time setup customer is not deploying, they are approving.
               Nothing goes live when they press the button — a setup-only
               campaign is paused as it deploys — so the page says Create, and
               says where the ads land and who turns them on.
            */
            'setupOnly' => $campaign->customer->service_type === 'setup_only',
            /*
               Whether work is still arriving, so the page knows to poll.

               Polling only ever started when the visitor pressed Generate on
               this page. Signing off a strategy dispatches the whole set from
               somewhere else entirely, so the ordinary path — sign off, land
               here, watch — showed whatever existed at page load and then sat
               still. Images appeared in storage and the customer saw a
               half-finished set until they refreshed by hand.

               Judged on what is missing rather than on queue state, which is
               not reliably queryable: a signed-off strategy with no ad copy or
               fewer than the three images the dispatcher sends is still being
               worked on. Bounded by time so an old strategy whose generation
               genuinely failed does not poll for ever.
            */
            'generationPending' => $this->generationPending($strategy),
        ]);
    }

    /**
     * Is this strategy's collateral still being produced?
     *
     * GenerateStrategyCollateral dispatches one ad copy job and three image
     * jobs; anything short of that, on a strategy signed off recently enough
     * for those jobs to still be alive, means more is coming.
     */
    private function generationPending(Strategy $strategy): bool
    {
        if (! $strategy->signed_off_at) {
            return false;
        }

        // Long enough for a staggered set plus a Veo chain, short enough that a
        // strategy whose generation failed months ago does not poll on load.
        if ($strategy->signed_off_at->lt(now()->subMinutes(30))) {
            return false;
        }

        if ($strategy->adCopies()->count() < 1) {
            return true;
        }

        // Count concepts, not format rows. The plan dispatches three concepts
        // per strategy; unused campaign allowance is not work still in flight.
        $campaign = $strategy->campaign;

        if (! $campaign) {
            return false;
        }

        $rows = $strategy->imageCollaterals()->get(['id', 'concept_key']);
        $have = $rows->whereNotNull('concept_key')->unique('concept_key')->count()
            + $rows->whereNull('concept_key')->count();

        return $have < \App\Services\Campaigns\CollateralPlan::IMAGE_CONCEPTS_PER_STRATEGY
            && ImageCollateral::canGenerateForCampaign($campaign)
            && $strategy->signed_off_at->gt(now()->subMinutes(self::IMAGE_GENERATION_WINDOW_MINUTES));
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
