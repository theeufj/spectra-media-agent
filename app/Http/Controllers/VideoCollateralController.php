<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateVideo;
use App\Jobs\ExtendVideo;
use App\Jobs\CheckVideoStatus;
use App\Models\Campaign;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\CreativeQuotaService;
use App\Services\StorageHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

            // Check creative quota
            $quotaService = app(CreativeQuotaService::class);
            if (!$quotaService->canGenerate($user, 'video')) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'Monthly video generation limit reached. Purchase a Creative Boost pack for more.',
                ]);
            }

            Log::info("Dispatching GenerateVideo job for Strategy ID: {$strategy->id}...");
            GenerateVideo::dispatch($campaign, $strategy, $validated['platform']);
            Log::info("GenerateVideo job dispatched successfully.");

            $quotaService->recordUsage($user, 'video');

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
     * @return \Illuminate\Http\RedirectResponse
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
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'Video must be completed before it can be extended.'
                ]);
            }

            if (!$video->gemini_video_uri) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'This video cannot be extended. Only Veo-generated videos can be extended.'
                ]);
            }

            $extensionCount = $video->extension_count ?? 0;
            if ($extensionCount >= 20) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'Maximum extension limit (20) reached for this video.'
                ]);
            }

            // Check creative quota (extensions count against video budget)
            $quotaService = app(CreativeQuotaService::class);
            if (!$quotaService->canGenerate($user, 'video')) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'Monthly video generation limit reached. Purchase a Creative Boost pack for more.',
                ]);
            }

            if (!$quotaService->canExtendVideo($video, $user)) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'This video has reached its maximum extension limit (3 extensions).',
                ]);
            }

            // Validate request
            $validated = $request->validate([
                'prompt' => 'nullable|string|max:1000',
            ]);

            Log::info("Dispatching ExtendVideo job for VideoCollateral ID: {$video->id}");
            ExtendVideo::dispatch($video, $validated['prompt'] ?? '');

            $quotaService->recordUsage($user, 'video');

            return redirect()->back()->with('flash', [
                'type' => 'success',
                'message' => 'Video extension has been queued. This process can take several minutes.',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error("Validation failed for video extension request. Errors: " . json_encode($e->errors()));
            throw $e;
        } catch (\Exception $e) {
            Log::error("An unexpected error occurred during video extension for VideoCollateral ID: {$video->id}. Error: " . $e->getMessage());
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Failed to start video extension: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Upload a user-provided video for a campaign strategy.
     */
    public function upload(Request $request, Campaign $campaign, Strategy $strategy)
    {
        $user = Auth::user();
        if (!$user->customers()->where('customers.id', $campaign->customer_id)->exists()) {
            abort(403, 'Unauthorized action.');
        }

        if ($strategy->campaign_id !== $campaign->id) {
            abort(403, 'Strategy does not belong to this campaign.');
        }

        $request->validate([
            'video' => 'required|file|mimes:mp4,mov,webm|max:102400', // 100MB
        ]);

        // Enforce per-campaign upload limit (3 uploaded videos)
        $existingUploads = VideoCollateral::where('campaign_id', $campaign->id)
            ->where('source', 'uploaded')
            ->count();

        if ($existingUploads >= 3) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Upload limit: 3 videos per campaign. Delete an existing upload to add more.',
            ]);
        }

        $file = $request->file('video');
        $contents = file_get_contents($file->getPathname());
        $ext = $file->getClientOriginalExtension() ?: 'mp4';
        $path = 'collateral/videos/' . $campaign->id . '/' . Str::uuid() . '.' . $ext;
        $contentType = $file->getMimeType() ?: 'video/mp4';

        [$s3Path, $url] = StorageHelper::put($path, $contents, $contentType);

        VideoCollateral::create([
            'campaign_id' => $campaign->id,
            'strategy_id' => $strategy->id,
            'platform' => $strategy->platform,
            's3_path' => $s3Path,
            'cloudfront_url' => $url,
            'status' => 'completed',
            'is_active' => true,
            'source' => 'uploaded',
        ]);

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Video uploaded successfully.',
        ]);
    }

    /**
     * Delete a video collateral (any source — AI-generated or uploaded).
     */
    public function destroy(VideoCollateral $video)
    {
        $user = Auth::user();
        $customerId = $video->campaign?->customer_id ?? $video->strategy?->campaign?->customer_id;
        if (!$customerId || !$user->customers()->where('customers.id', $customerId)->exists()) {
            abort(403, 'Unauthorized action.');
        }

        if ($video->s3_path) {
            StorageHelper::delete($video->s3_path);
        }
        $video->delete();

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Video deleted.',
        ]);
    }
}
