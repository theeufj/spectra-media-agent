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
            return response()->json(['message' => 'Invalid collateral type provided.'], 400);
        }

        $collateral = $modelClass::findOrFail($validated['id']);

        // Authorization check: Ensure the user owns the campaign this collateral belongs to.
        // Campaign belongs to Customer, which belongs to User
        $campaign = $collateral->campaign ?? $collateral->strategy?->campaign;
        
        if (!$campaign) {
            return response()->json(['message' => 'Campaign not found for this collateral.'], 404);
        }

        $customer = $campaign->customer;
        if (!$customer || !$request->user()->customers()->where('customers.id', $customer->id)->exists()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $collateral->update(['should_deploy' => !$collateral->should_deploy]);

        return response()->json([
            'message' => 'Deployment status updated successfully.',
            'should_deploy' => $collateral->should_deploy,
        ]);
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

        // Dispatch the deployment job with execution agents enabled
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
}
