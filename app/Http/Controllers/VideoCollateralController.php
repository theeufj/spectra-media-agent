<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateVideo;
use App\Jobs\CheckVideoStatus;
use App\Models\Campaign;
use App\Models\Strategy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class VideoCollateralController extends Controller
{
    /**
     * store is the handler for dispatching the video generation job.
     *
     * @param Request $request
     * @param Campaign $campaign
     * @param Strategy $strategy
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, Campaign $campaign, Strategy $strategy)
    {
        Log::info("Video generation request received for Campaign ID: {$campaign->id}, Strategy ID: {$strategy->id} with raw platform: '{$request->input('platform')}'");

        try {
            if ($campaign->user_id !== Auth::id() || $strategy->campaign_id !== $campaign->id) {
                Log::warning("Unauthorized attempt to generate video for Campaign ID: {$campaign->id} by User ID: " . Auth::id());
                abort(403, 'Unauthorized action.');
            }

            // Standardize the platform input
            $platformInput = strtolower($request->input('platform', ''));
            $standardizedPlatform = null;

            if (str_contains($platformInput, 'google')) {
                $standardizedPlatform = 'google';
            } elseif (str_contains($platformInput, 'facebook')) {
                $standardizedPlatform = 'facebook';
            } elseif (str_contains($platformInput, 'instagram')) {
                $standardizedPlatform = 'instagram';
            } elseif (str_contains($platformInput, 'tiktok')) {
                $standardizedPlatform = 'tiktok';
            } elseif (str_contains($platformInput, 'linkedin')) {
                $standardizedPlatform = 'linkedin';
            }

            $request->merge(['platform' => $standardizedPlatform]);

            Log::info("Authorisation successful. Validating request payload with standardized platform: '{$standardizedPlatform}'");
            $validated = $request->validate([
                'platform' => 'required|string|in:google,facebook,instagram,tiktok,linkedin',
            ]);
            Log::info("Request payload validated successfully for platform: {$validated['platform']}.");

            Log::info("Dispatching GenerateVideo job for Strategy ID: {$strategy->id}...");
            GenerateVideo::dispatch($campaign, $strategy, $validated['platform']);
            Log::info("GenerateVideo job dispatched successfully.");

            return redirect()->back()->with('flash', [
                'type' => 'success',
                'message' => 'Video generation has been queued. This process can take several minutes. You will be notified upon completion.'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error("Validation failed for video generation request. Errors: " . json_encode($e->errors()));
            // Re-throw the validation exception to let Laravel handle the response.
            throw $e;
        } catch (\Exception $e) {
            Log::error("An unexpected error occurred during video generation dispatch for Strategy ID: {$strategy->id}. Error: " . $e->getMessage());
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Failed to start video generation: An unexpected error occurred. Please check the logs for more details.'
            ]);
        }
    }
}
