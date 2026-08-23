<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Http\Requests\DeployCollateralRequest;
use App\Jobs\DeployCampaign;
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

        $collateral->update([$field => ! $collateral->{$field}]);

        return back();
    }

    /**
     * Handles the final deployment of the selected collateral.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deploy(Request $request): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
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

        // 1. Subscription Check — the shared teammate-aware rule (also what
        //    the EnsureSubscribed middleware now applies).
        if (! $user->hasSubscriptionAccess($customer)) {
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

        // Already in the admin queue: re-clicking Deploy used to wipe the
        // strategies' statuses, send another raw email, and repeat the same
        // success message with nothing new happening.
        if ($campaign->status === CampaignStatus::PendingAdminDeployment) {
            return redirect()->back()->with('flash', [
                'type' => 'info',
                'message' => 'This campaign is already with our team for launch — we\'ll notify you as soon as it\'s live.',
            ]);
        }

        // A deploy already in flight: don't reset its status rows (that blinds
        // verification), and don't dispatch again (ShouldBeUnique would drop
        // it silently while we claimed success).
        if ($campaign->strategies()->where('deployment_status', 'deploying')->exists()) {
            return redirect()->route('campaigns.deployment-status', $campaign)->with('flash', [
                'type' => 'info',
                'message' => 'A deployment is already running for this campaign — here\'s its progress.',
            ]);
        }

        // Reset deployment_status on all signed-off strategies so an explicit
        // "Deploy All" always re-deploys, even if previously marked deployed/verified.
        $campaign->strategies()
            ->whereNotNull('signed_off_at')
            ->update(['deployment_status' => null]);

        // Google readiness: no account ID, or an account whose manager link
        // isn't active. Both used to reach the execution agent and die with
        // PERMISSION_DENIED after the card had been charged — the link-status
        // case even triggered the identity-verification email, the wrong
        // remedy entirely. Queue for the admin team instead.
        $hasGoogleStrategy = $campaign->strategies()
            ->whereNotNull('signed_off_at')
            ->where(fn ($q) => $q->where('platform', 'like', '%google%')->orWhere('platform', 'like', '%Google%'))
            ->exists();

        $linkProblem = in_array($customer->google_ads_link_status, ['pending', 'refused', 'cancelled', 'failed'], true);

        if ($hasGoogleStrategy && (empty($customer->google_ads_customer_id) || $linkProblem)) {
            $reason = empty($customer->google_ads_customer_id)
                ? 'The customer has no Google Ads account ID. Create a sub-account under the MCC, attach it in the admin portal, then click Deploy on this campaign.'
                : "The customer's Google Ads manager link is '{$customer->google_ads_link_status}' — resolve the link (or re-invite), then click Deploy on this campaign.";

            $campaign->update([
                'status' => CampaignStatus::PendingAdminDeployment,
                'pending_admin_deployment_at' => now(),
            ]);

            \Illuminate\Support\Facades\Mail::raw(
                "Campaign pending deployment — admin action required\n\n"
                ."Customer: {$customer->business_name} (ID: {$customer->id})\n"
                ."Campaign: {$campaign->name} (ID: {$campaign->id})\n"
                ."Budget: \${$campaign->daily_budget}/day\n"
                ."Strategies: {$signedOffCount} signed off\n\n"
                .$reason."\n\n"
                .url(route('admin.customers.show', $customer->id)),
                fn ($m) => $m->to(config('app.admin_email'))
                    ->subject("Action required: Deploy \"{$campaign->name}\" for {$customer->business_name}")
            );

            // Also raise the in-product admin alert — a raw email with no
            // follow-up was the entire SLA machinery behind "within 24 hours".
            \App\Notifications\CriticalAgentAlert::deliver(
                'pending_admin_deployment',
                "Deploy \"{$campaign->name}\" for {$customer->business_name}",
                $reason,
                ['campaign_id' => $campaign->id, 'customer_id' => $customer->id],
                \App\Models\NotificationTemplate::RECIPIENTS_ADMINS,
                $customer
            );

            ActivityLog::log('campaign_pending_admin_deployment', "Campaign '{$campaign->name}' queued for admin deployment", $campaign, [
                'campaign_id' => $campaign->id,
                'customer_id' => $customer->id,
                'reason' => $reason,
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

        // Land the user on the page that actually shows what happens next.
        // Deployment runs for minutes and can fail per-platform; a toast on the
        // collateral page communicated neither.
        return redirect()->route('campaigns.deployment-status', $campaign)->with('flash', [
            'type' => 'success',
            'message' => 'Deployment started — you can watch each platform\'s progress here.',
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
        if (! $user->hasSubscriptionAccess($customer)) {
            return redirect()->route('subscription.pricing')->with('flash', [
                'type' => 'error',
                'message' => 'You must have an active subscription to deploy campaigns.',
            ]);
        }

        if (! Setting::get('deployment_enabled', true)) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Deployment is currently disabled.',
            ]);
        }

        if (! $strategy->signed_off_at) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => "The {$strategy->platform} strategy must be signed off before deploying.",
            ]);
        }

        // Reset deployment_status so the idempotency guard allows re-deployment of this strategy.
        $strategy->update(['deployment_status' => null]);

        DeployCampaign::dispatch($campaign, useAgents: true, strategyId: $strategy->id);

        Log::info('Single-platform deploy dispatched', [
            'campaign_id' => $campaign->id,
            'strategy_id' => $strategy->id,
            'platform' => $strategy->platform,
            'user_id' => $user->id,
        ]);

        ActivityLog::log('campaign_deployed', "Single-platform deployment initiated for '{$strategy->platform}' on campaign '{$campaign->name}'", $campaign, [
            'campaign_id' => $campaign->id,
            'strategy_id' => $strategy->id,
            'platform' => $strategy->platform,
        ]);

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => "{$strategy->platform} deployment has been initiated!",
        ]);
    }
}
