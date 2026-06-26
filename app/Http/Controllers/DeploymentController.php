<?php

namespace App\Http\Controllers;

use App\Jobs\DeployCampaign;
use App\Http\Requests\DeployCollateralRequest;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeploymentController extends Controller
{
    /**
     * Toggles the deployment status of a given piece of collateral.
     *
     * @param Request $request
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

        if (!$modelClass) {
            abort(400, 'Invalid collateral type provided.');
        }

        $collateral = $modelClass::findOrFail($validated['id']);

        // Authorization check: Ensure the user owns the campaign this collateral belongs to.
        $campaign = $collateral->campaign ?? $collateral->strategy?->campaign;

        if (!$campaign) {
            abort(404, 'Campaign not found for this collateral.');
        }

        $customer = $campaign->customer;
        if (!$customer || !$request->user()->customers()->where('customers.id', $customer->id)->exists()) {
            abort(403);
        }

        $collateral->update(['should_deploy' => !$collateral->should_deploy]);

        return back();
    }

    /**
     * Handles the final deployment of the selected collateral.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function deploy(Request $request)
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
        ]);

        // Get campaign and verify ownership
        $campaign = Campaign::findOrFail($validated['campaign_id']);
        $customer = $user->customers()->findOrFail(session('active_customer_id'));
        
        if ($campaign->customer_id !== $customer->id) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Unauthorized access to this campaign.',
            ]);
        }

        // 1. Subscription Check — passes if the current user OR any teammate on the same
        //    customer account has an active subscription / payment method.
        //    Team members (admin/member roles) share the company's plan.
        $userHasAccess = $user->subscribed('default')
            || $user->hasDefaultPaymentMethod()
            || $user->subscription_status === 'active';

        $customerHasAccess = $userHasAccess || $customer->users()
            ->where(function ($q) {
                $q->where('subscription_status', 'active')
                  ->orWhereNotNull('pm_type')
                  ->orWhereHas('subscriptions', fn ($sq) => $sq->where('stripe_status', 'active'));
            })
            ->exists();

        if (!$customerHasAccess) {
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
        
        if (!$deploymentEnabled) {
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
        $signedOffCount = $campaign->strategies()->whereNotNull('signed_off_at')->count();
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

        // Reset deployment_status on all signed-off strategies so an explicit
        // "Deploy All" always re-deploys, even if previously marked deployed/verified.
        $campaign->strategies()
            ->whereNotNull('signed_off_at')
            ->update(['deployment_status' => null]);

        // If the customer doesn't have a Google Ads account ID yet, we can't deploy
        // programmatically. Queue the campaign for manual admin deployment instead —
        // the admin team will create the account, attach it, and deploy from the portal.
        $hasGoogleStrategy = $campaign->strategies()
            ->whereNotNull('signed_off_at')
            ->where(fn ($q) => $q->where('platform', 'like', '%google%')->orWhere('platform', 'like', '%Google%'))
            ->exists();

        if ($hasGoogleStrategy && empty($customer->google_ads_customer_id)) {
            $campaign->update(['status' => 'pending_admin_deployment']);

            \Illuminate\Support\Facades\Mail::raw(
                "Campaign pending deployment — admin action required\n\n"
                . "Customer: {$customer->business_name} (ID: {$customer->id})\n"
                . "Campaign: {$campaign->name} (ID: {$campaign->id})\n"
                . "Budget: \${$campaign->daily_budget}/day\n"
                . "Strategies: {$signedOffCount} signed off\n\n"
                . "The customer has no Google Ads account ID. Create a sub-account under the MCC,\n"
                . "attach it in the admin portal, then click Deploy on this campaign:\n\n"
                . url(route('admin.customers.show', $customer->id)),
                fn ($m) => $m->to('theeufj@gmail.com')
                    ->subject("Action required: Deploy \"{$campaign->name}\" for {$customer->business_name}")
            );

            ActivityLog::log('campaign_pending_admin_deployment', "Campaign '{$campaign->name}' queued for admin deployment — no Google Ads account ID", $campaign, [
                'campaign_id' => $campaign->id,
                'customer_id' => $customer->id,
            ]);

            Log::info("Campaign queued for admin deployment — no Google Ads account ID", [
                'campaign_id' => $campaign->id,
                'customer_id' => $customer->id,
            ]);

            return redirect()->back()->with('flash', [
                'type' => 'success',
                'message' => 'Your campaign has been submitted! Our team will complete the setup and launch your ads within 24 hours. We\'ll notify you when it\'s live.',
            ]);
        }

        DeployCampaign::dispatch($campaign, useAgents: true);

        ActivityLog::log('campaign_deployed', "Campaign '{$campaign->name}' deployment initiated ({$signedOffCount} strategies)", $campaign, [
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'signed_off_strategies' => $signedOffCount,
        ]);

        Log::info("📤 DeployCampaign job dispatched for Campaign ID: {$campaign->id}");

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Campaign deployment has been initiated! Your ads will be deployed to the selected platforms shortly.',
        ]);
    }

    /**
     * Deploy a single platform strategy.
     */
    public function deployPlatform(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'campaign_id' => 'required|integer|exists:campaigns,id',
            'strategy_id' => 'required|integer|exists:strategies,id',
        ]);

        $campaign = Campaign::findOrFail($validated['campaign_id']);
        $customer = $user->customers()->findOrFail(session('active_customer_id'));

        if ($campaign->customer_id !== $customer->id) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Unauthorized access to this campaign.',
            ]);
        }

        $strategy = $campaign->strategies()->findOrFail($validated['strategy_id']);

        // Re-use the same subscription + deployment-enabled checks as the full deploy.
        $userHasAccess = $user->subscribed('default')
            || $user->hasDefaultPaymentMethod()
            || $user->subscription_status === 'active';

        $customerHasAccess = $userHasAccess || $customer->users()
            ->where(function ($q) {
                $q->where('subscription_status', 'active')
                  ->orWhereNotNull('pm_type')
                  ->orWhereHas('subscriptions', fn ($sq) => $sq->where('stripe_status', 'active'));
            })
            ->exists();

        if (!$customerHasAccess) {
            return redirect()->route('subscription.pricing')->with('flash', [
                'type' => 'error',
                'message' => 'You must have an active subscription to deploy campaigns.',
            ]);
        }

        if (!Setting::get('deployment_enabled', true)) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Deployment is currently disabled.',
            ]);
        }

        if (!$strategy->signed_off_at) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => "The {$strategy->platform} strategy must be signed off before deploying.",
            ]);
        }

        // Reset deployment_status so the idempotency guard allows re-deployment of this strategy.
        $strategy->update(['deployment_status' => null]);

        DeployCampaign::dispatch($campaign, useAgents: true, strategyId: $strategy->id);

        Log::info("Single-platform deploy dispatched", [
            'campaign_id' => $campaign->id,
            'strategy_id' => $strategy->id,
            'platform'    => $strategy->platform,
            'user_id'     => $user->id,
        ]);

        ActivityLog::log('campaign_deployed', "Single-platform deployment initiated for '{$strategy->platform}' on campaign '{$campaign->name}'", $campaign, [
            'campaign_id' => $campaign->id,
            'strategy_id' => $strategy->id,
            'platform'    => $strategy->platform,
        ]);

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => "{$strategy->platform} deployment has been initiated!",
        ]);
    }
}
