<?php

namespace App\Http\Controllers;

use App\Jobs\DeployCampaign;
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
    public function toggleCollateral(Request $request)
    {
        Log::info('🔄 Toggle collateral called', [
            'user_id' => auth()->id(),
            'request_data' => $request->all(),
        ]);

        $validated = $request->validate([
            'type' => 'required|string|in:ad_copy,image,video',
            'id' => 'required|integer',
        ]);

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
        if (!$customer || $customer->user_id !== auth()->id()) {
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
            return response()->json([
                'message' => 'Unauthorized access to this campaign.',
            ], 403);
        }

        // 1. Subscription Check (or payment method in testing mode)
        $hasAccess = $user->subscribed('default') || $user->hasDefaultPaymentMethod();
        
        if (!$hasAccess) {
            return response()->json([
                'message' => 'You must have an active subscription to deploy campaigns.',
                'redirect' => route('subscription.pricing'),
            ], 403);
        }

        // 2. Deployment Enabled Check (Admin Setting)
        $deploymentEnabled = Setting::get('deployment_enabled', true);
        
        if (!$deploymentEnabled) {
            return response()->json([
                'message' => 'We\'re currently enhancing our deployment system to serve you better. Campaign deployment will be available soon! Your subscription remains active and you can continue creating campaigns.',
                'type' => 'maintenance',
            ], 503);
        }

        // 3. Check if campaign has strategies
        if ($campaign->strategies()->count() === 0) {
            return response()->json([
                'message' => 'This campaign has no strategies to deploy.',
            ], 400);
        }

        // 4. Check if at least one strategy is signed off
        $signedOffCount = $campaign->strategies()->whereNotNull('signed_off_at')->count();
        if ($signedOffCount === 0) {
            return response()->json([
                'message' => 'Please sign off at least one strategy before deploying.',
            ], 400);
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
        
        Log::info("📤 DeployCampaign job dispatched for Campaign ID: {$campaign->id}");
        
        return response()->json([
            'message' => 'Campaign deployment has been initiated! Your ads will be deployed to the selected platforms shortly.',
            'campaign_id' => $campaign->id,
        ]);
    }
}
