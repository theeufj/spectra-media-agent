<?php

namespace App\Http\Controllers;

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
        if ($collateral->campaign->user_id !== auth()->id()) {
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

        // 1. Subscription Check (or payment method in testing mode)
        $hasAccess = $user->subscribed('default') || $user->hasDefaultPaymentMethod();
        
        if (!$hasAccess) {
            return response()->json([
                'message' => 'You must have an active subscription to deploy campaigns.',
                'redirect' => route('subscription.pricing'),
            ], 403);
        }

        // 2. Deployment Enabled Check
        $deploymentEnabled = Setting::get('deployment_enabled', true);
        
        if (!$deploymentEnabled) {
            return response()->json([
                'message' => 'We\'re currently enhancing our deployment system to serve you better. Campaign deployment will be available soon! Your subscription remains active and you can continue creating campaigns.',
                'type' => 'maintenance',
            ], 503);
        }

        // In a real application, this is where you would dispatch jobs
        // to deploy the collateral to the various ad platforms.
        // For now, we will just log a message.

        Log::info("Deployment initiated by User ID: {$user->id}");

        // Here you would find all the collateral with should_deploy = true and
        // dispatch jobs to deploy them.
        // Example:
        // $adCopiesToDeploy = AdCopy::where('should_deploy', true)->whereHas('campaign', fn($q) => $q->where('user_id', $user->id))->get();
        // foreach($adCopiesToDeploy as $adCopy) {
        //     DeployAdCopy::dispatch($adCopy);
        // }
        
        return response()->json(['message' => 'Deployment has been initiated.']);
    }
}
