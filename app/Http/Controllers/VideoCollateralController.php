<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateVideo;
use App\Jobs\ExtendVideo;
use App\Jobs\CheckVideoStatus;
use App\Models\Campaign;
use App\Models\Strategy;
use App\Models\VideoCollateral;
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
            // Ensure the campaign belongs to a customer that the authenticated user is part of.
            $user = Auth::user();
            if (!$user->customers()->where('customers.id', $campaign->customer_id)->exists() || $strategy->campaign_id !== $campaign->id) {
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

    /**
     * Extend an existing Veo-generated video by up to 7 seconds.
     * Can extend up to 20 times (cumulative 148 seconds max).
     *
     * @param Request $request
     * @param VideoCollateral $video
     * @return \Illuminate\Http\JsonResponse
     */
    public function extend(Request $request, VideoCollateral $video)
    {
        Log::info("Video extension request received for VideoCollateral ID: {$video->id}");

        try {
            // Ensure the video belongs to a campaign that the authenticated user has access to
            $user = Auth::user();
            $campaign = $video->campaign;
            
            if (!$user->customers()->where('customers.id', $campaign->customer_id)->exists()) {
                Log::warning("Unauthorized attempt to extend video ID: {$video->id} by User ID: " . Auth::id());
                abort(403, 'Unauthorized action.');
            }

            // Validate the video can be extended
            if ($video->status !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Video must be completed before it can be extended.'
                ], 400);
            }

            if (!$video->gemini_video_uri) {
                return response()->json([
                    'success' => false,
                    'message' => 'This video cannot be extended. Only Veo-generated videos can be extended.'
                ], 400);
            }

            $extensionCount = $video->extension_count ?? 0;
            if ($extensionCount >= 20) {
                return response()->json([
                    'success' => false,
                    'message' => 'Maximum extension limit (20) reached for this video.'
                ], 400);
            }

            // Validate request
            $validated = $request->validate([
                'prompt' => 'required|string|max:1000',
            ]);

            Log::info("Dispatching ExtendVideo job for VideoCollateral ID: {$video->id}");
            ExtendVideo::dispatch($video, $validated['prompt']);

            return response()->json([
                'success' => true,
                'message' => 'Video extension has been queued. This process can take several minutes.',
                'extensions_remaining' => 20 - $extensionCount - 1
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error("Validation failed for video extension request. Errors: " . json_encode($e->errors()));
            throw $e;
        } catch (\Exception $e) {
            Log::error("An unexpected error occurred during video extension for VideoCollateral ID: {$video->id}. Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to start video extension: ' . $e->getMessage()
            ], 500);
        }
    }
}
