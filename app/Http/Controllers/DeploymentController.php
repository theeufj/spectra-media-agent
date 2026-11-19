<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeployCollateralRequest;
use App\Jobs\ProvisionGoogleAdsAccount;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Setting;
use App\Services\Deployment\CampaignLaunchIntent;
use App\Services\DeploymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DeploymentController extends Controller
{
    /**
     * Toggles the deployment status of a given piece of collateral.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleCollateral(DeployCollateralRequest $request)
    {
        Log::info('🔄 Toggle collateral called', [
            'user_id' => auth()->id(),
            'request_data' => $request->all(),
        ]);

        $validated = $request->validated();

        $modelClass = match ($validated['type']) {
            'ad_copy' => \App\Models\AdCopy::class,
            'image' => \App\Models\ImageCollateral::class,
            'video' => \App\Models\VideoCollateral::class,
            default => null,
        };

        if (! $modelClass) {
            abort(400, 'Invalid collateral type provided.');
        }

        $collateral = $modelClass::findOrFail($validated['id']);

        // Authorization check: Ensure the user owns the campaign this collateral belongs to.
        $campaign = $collateral->campaign ?? $collateral->strategy?->campaign;

        if (! $campaign) {
            abort(404, 'Campaign not found for this collateral.');
        }

        $customer = $campaign->customer;
        if (! $customer || ! $request->user()->can('view', $customer)) {
            abort(403);
        }

        $field = $validated['field'] ?? 'should_deploy';

        if ($field === 'is_seed' && $validated['type'] !== 'image') {
            abort(400, 'Only images can be marked as AI seeds.');
        }

        /*
         * An explicit value, when the caller has one.
         *
         * A card on the collateral page is one photograph stored in three ad
         * sizes, and approving it approves all three at once. Flipping each row
         * independently is wrong there: if the three had drifted out of step,
         * one flip per row preserves the drift instead of resolving it, and the
         * campaign deploys with some sizes missing. Toggling stays the default
         * so every existing caller behaves exactly as before.
         */
        $value = array_key_exists('value', $validated)
            ? (bool) $validated['value']
            : ! $collateral->{$field};

        $collateral->update([$field => $value]);

        return back();
    }

    /**
     * Handles the final deployment of the selected collateral.
     */
    public function deploy(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->launch($request);
    }

    public function deployPlatform(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->launch($request, singlePlatform: true);
    }

    private function launch(Request $request, bool $singlePlatform = false): \Illuminate\Http\RedirectResponse
    {
        $user = $request->user();

        Log::info('🚀 Deploy endpoint called', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'request_data' => $request->all(),
            'active_customer_id' => session('active_customer_id'),
        ]);

        // Validate campaign ID
        $validated = $request->validate([
            'campaign_id' => 'required|integer|exists:campaigns,id',
            'strategy_id' => $singlePlatform ? 'required|integer|exists:strategies,id' : 'nullable|integer',
            'creative_fingerprints' => 'sometimes|array',
            'creative_fingerprints.*' => 'required|string|regex:/^[a-f0-9]{64}$/',
        ]);

        // Get campaign and verify ownership
        $campaign = Campaign::findOrFail($validated['campaign_id']);
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return redirect()->route('quick-start');
        }

        $this->authorize('view', $campaign);
        abort_unless($campaign->customer_id === $customer->id, 404);
        $selectedStrategy = $singlePlatform ? $campaign->strategies()->findOrFail($validated['strategy_id']) : null;
        $strategyId = $selectedStrategy?->id;
        $selected = fn () => $campaign->strategies()->when($strategyId !== null, fn ($query) => $query->whereKey($strategyId));

        if ($selected()->whereNotNull('signed_off_at')
            ->whereIn('creative_review->status', ['pending', 'reviewing', 'revising'])->exists()) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Your creative is still being prepared and checked. Review the finished set before creating your ads.']);
        }

        // 0. First launch requires a signed-off brand profile. Everything we
        //    deploy is written from the guidelines, so the user must have
        //    looked at them and confirmed they represent the business before
        //    the first ad ever runs. Customers who have launched before are
        //    not re-gated.
        $brand = $customer->brandGuideline;
        if (! $brand?->user_verified || ($brand->approved_version !== null && $brand->approved_version !== $brand->profile_version)) {
            ActivityLog::log('campaign_deploy_blocked', "Deploy blocked — brand profile not confirmed for campaign '{$campaign->name}'", $campaign, [
                'campaign_id' => $campaign->id,
                'reason' => 'brand_guideline_unverified',
            ]);

            return redirect()->route('brand-guidelines.index', ['review' => 1, 'return_campaign' => $campaign->uuid,
                ...($selectedStrategy ? ['return_strategy' => $selectedStrategy->uuid] : []),
            ])->with('flash', [
                'type' => 'error',
                'message' => 'Review and confirm the current brand profile before creating ads. Every ad we write starts from this profile.',
            ]);
        }

        // 1. Subscription Check — the shared teammate-aware rule (also what
        //    the EnsureSubscribed middleware now applies).
        if (! $customer->hasPaidDeploymentAccess() || ! $user->hasSubscriptionAccess($customer)) {
            ActivityLog::log('campaign_deploy_blocked', "Deploy blocked — no active subscription for campaign '{$campaign->name}'", $campaign, [
                'campaign_id' => $campaign->id,
                'reason' => 'no_subscription',
            ]);

            return redirect()->route('subscription.pricing')->with('flash', [
                'type' => 'error',
                'message' => 'You must have an active subscription to deploy campaigns.',
            ]);
        }

        // 2. Deployment Enabled Check (Admin Setting)
        $deploymentEnabled = Setting::get('deployment_enabled', true);

        if (! $deploymentEnabled) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'We\'re currently enhancing our deployment system to serve you better. Campaign deployment will be available soon! Your subscription remains active and you can continue creating campaigns.',
            ]);
        }

        // 3. Check if campaign has strategies
        if ($campaign->strategies()->count() === 0) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'This campaign has no strategies to deploy.',
            ]);
        }

        // 4. Check if at least one strategy is signed off
        $signedOffCount = $selected()->whereNotNull('signed_off_at')->count();
        if ($signedOffCount === 0) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Please sign off at least one strategy before deploying.',
            ]);
        }

        Log::info("✅ Deployment initiated by User ID: {$user->id} for Campaign ID: {$campaign->id}", [
            'campaign_name' => $campaign->name,
            'customer_id' => $customer->id,
            'signed_off_strategies' => $signedOffCount,
            'has_subscription' => $user->subscribed('default'),
            'has_payment_method' => $user->hasDefaultPaymentMethod(),
        ]);

        // An auto-generated campaign carries a daily budget a language model
        // proposed. That number is not cosmetic: deploying charges seven days
        // of it up front. Nobody is billed against a figure a human has not
        // looked at, so the confirmation is enforced here rather than trusted
        // to the review screen — a form field can be bypassed, this cannot.
        if ($campaign->auto_generated_at && ! $campaign->budget_confirmed_at) {
            Log::info('Deployment blocked: auto-generated budget not confirmed', [
                'campaign_id' => $campaign->id,
                'suggested_daily_budget' => $campaign->daily_budget,
            ]);

            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Confirm your daily budget before deploying — we suggested one, but the first seven days are charged up front so we need you to approve it.',
            ]);
        }

        $launch = app(CampaignLaunchIntent::class);
        $credit = $customer->adSpendCredit()->first();
        $paymentNeedsAttention = ! $customer->isSelfFundedAds() && Setting::get('managed_billing_enabled', true)
            && $credit !== null && ! $credit->isInGoodStanding();
        $billingRedirect = fn () => redirect()->route('billing.ad-spend')->with('flash', [
            'type' => 'error',
            'message' => 'Update your ad spend payment method before creating ads. Your billing account needs attention.',
        ]);
        try {
            $launchId = $launch->request($campaign, $user, $strategyId, $validated['creative_fingerprints'] ?? []);
        } catch (ValidationException $e) {
            if ($paymentNeedsAttention) {
                return $billingRedirect();
            }

            return back()->with('flash', ['type' => 'error', 'message' => $e->validator->errors()->first()]);
        }
        if ($launchId === null) {
            if ($paymentNeedsAttention) {
                return $billingRedirect();
            }

            return redirect()->route('campaigns.deployment-status', $campaign)->with('flash', [
                'type' => 'info',
                'message' => 'This campaign already has a deployment. Check its progress below.',
            ]);
        }

        $queued = $launch->resume($campaign->id, $launchId);
        if (! $queued) {
            $strategies = $launch->strategies($campaign, $launchId, CampaignLaunchIntent::WAITING)->get();
            if ($strategies->contains(fn ($strategy) => DeploymentService::normalizePlatform($strategy->platform) === 'google')
                && CampaignLaunchIntent::canProvisionGoogle($customer)) {
                ProvisionGoogleAdsAccount::dispatchIfNeeded($customer->fresh());
            }
            if ($paymentNeedsAttention) {
                return $billingRedirect();
            }

            return redirect()->route('campaigns.deployment-status', $campaign)->with('flash', [
                'type' => 'success',
                'message' => 'Launch approved. Your ads will be created automatically when the account and funding are ready.',
            ]);
        }

        ActivityLog::log('campaign_deployed', "Campaign '{$campaign->name}' deployment initiated ({$signedOffCount} strategies)", $campaign, [
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'signed_off_strategies' => $signedOffCount,
        ]);

        Log::info("📤 DeployCampaign job dispatched for Campaign ID: {$campaign->id}");

        // Land the user on the page that actually shows what happens next.
        // Deployment runs for minutes and can fail per-platform; a toast on the
        // collateral page communicated neither.
        return redirect()->route('campaigns.deployment-status', $campaign)->with('flash', [
            'type' => 'success',
            'message' => 'Deployment started — you can watch each platform\'s progress here.',
        ]);
    }
}
