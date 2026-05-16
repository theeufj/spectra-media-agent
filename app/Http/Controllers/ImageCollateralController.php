<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateImage;
use App\Jobs\RefineImage;
use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Services\CreativeQuotaService;
use App\Services\StorageHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImageCollateralController extends Controller
{
    /**
     * store is the handler for dispatching the image generation job.
     *
     * @param Request $request
     * @param Campaign $campaign
     * @param Strategy $strategy
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, Campaign $campaign, Strategy $strategy)
    {
        // Log the request to generate an image.
        Log::info("Image generation requested for Campaign ID: {$campaign->id}, Strategy ID: {$strategy->id}");

        // Ensure the campaign belongs to a customer that the authenticated user is part of.
        $user = Auth::user();
        if (!$user->customers()->where('customers.id', $campaign->customer_id)->exists()) {
            abort(403, 'Unauthorized action.');
        }

        // Ensure the strategy belongs to the campaign.
        if ($strategy->campaign_id !== $campaign->id) {
            abort(403, 'Strategy does not belong to this campaign.');
        }

        // Check creative quota
        $quotaService = app(CreativeQuotaService::class);
        if (!$quotaService->canGenerate($user, 'image')) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Monthly image generation limit reached. Purchase a Creative Boost pack for more.',
            ]);
        }

        // Dispatch the job to handle the image generation in the background.
        GenerateImage::dispatch($campaign, $strategy);

        $quotaService->recordUsage($user, 'image');

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Image generation has been queued. You will be notified upon completion.'
        ]);
    }

    /**
     * update is the handler for dispatching the image refinement job.
     *
     * @param Request $request
     * @param ImageCollateral $imageCollateral
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, ImageCollateral $imageCollateral)
    {
        $request->validate([
            'prompt' => 'required|string|min:10',
            'context_image' => 'nullable|image|max:4096', // Max 4MB
        ]);

        // Ensure the user is authorized to edit this image.
        $user = Auth::user();
        if (!$user->customers()->where('customers.id', $imageCollateral->campaign->customer_id)->exists()) {
            abort(403, 'Unauthorized action.');
        }

        // Check creative quota for refinements
        $quotaService = app(CreativeQuotaService::class);
        if (!$quotaService->canGenerate($user, 'refinement')) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Monthly refinement limit reached. Purchase a Creative Boost pack for more.',
            ]);
        }

        if (!$quotaService->canRefineImage($imageCollateral, $user)) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'This image has reached its maximum refinement limit (3 edits).',
            ]);
        }

        $contextImagePath = null;
        if ($request->hasFile('context_image')) {
            // Store the uploaded context image locally temporarily.
            // The job will clean it up after processing.
            $contextImagePath = $request->file('context_image')->store('temp_context_images');
        }

        // Dispatch the job to handle the refinement.
        RefineImage::dispatch($imageCollateral, $request->prompt, $contextImagePath);

        $quotaService->recordUsage($user, 'refinement');

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Image refinement has been queued. Please check back in a few moments.'
        ]);
    }

    /**
     * Upload user-provided images for a campaign strategy.
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
            'images' => 'required|array|min:1|max:20',
            'images.*' => 'required|file|mimes:jpeg,jpg,png,webp|max:10240',
        ]);

        // Enforce per-campaign upload limit (10 uploaded images)
        $existingUploads = ImageCollateral::where('campaign_id', $campaign->id)
            ->where('source', 'uploaded')
            ->count();

        $incoming = count($request->file('images'));
        if ($existingUploads + $incoming > 10) {
            $remaining = max(0, 10 - $existingUploads);
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => "Upload limit: 10 images per campaign. You have {$existingUploads} uploaded, can add {$remaining} more.",
            ]);
        }

        // Platform dimension requirements
        $platform = strtolower($strategy->platform);
        $dimensionRules = [
            'google' => ['min_width' => 600, 'min_height' => 314, 'rec_width' => 1200, 'rec_height' => 628, 'label' => '1200×628 (landscape)'],
            'google.sem' => ['min_width' => 600, 'min_height' => 314, 'rec_width' => 1200, 'rec_height' => 628, 'label' => '1200×628 (landscape)'],
            'facebook' => ['min_width' => 1080, 'min_height' => 1080, 'rec_width' => 1080, 'rec_height' => 1080, 'label' => '1080×1080 (square)'],
            'linkedin' => ['min_width' => 1200, 'min_height' => 627, 'rec_width' => 1200, 'rec_height' => 627, 'label' => '1200×627 (landscape)'],
            'microsoft' => ['min_width' => 703, 'min_height' => 368, 'rec_width' => 1200, 'rec_height' => 628, 'label' => '1200×628 (landscape)'],
        ];

        $rules = $dimensionRules[$platform] ?? $dimensionRules['google'];
        $created = 0;
        $errors = [];

        foreach ($request->file('images') as $i => $file) {
            $imageSize = @getimagesize($file->getPathname());
            if (!$imageSize) {
                $errors[] = "{$file->getClientOriginalName()}: could not read image dimensions.";
                continue;
            }

            [$width, $height] = $imageSize;
            if ($width < $rules['min_width'] || $height < $rules['min_height']) {
                $errors[] = "{$file->getClientOriginalName()}: {$width}×{$height} is too small. Minimum: {$rules['min_width']}×{$rules['min_height']} for {$platform}.";
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $ext = $file->getClientOriginalExtension() ?: 'jpg';
            $path = 'collateral/images/' . $campaign->id . '/' . Str::uuid() . '.' . $ext;
            $contentType = $file->getMimeType() ?: 'image/jpeg';

            [$s3Path, $url] = StorageHelper::put($path, $contents, $contentType);

            ImageCollateral::create([
                'campaign_id' => $campaign->id,
                'strategy_id' => $strategy->id,
                'platform' => $strategy->platform,
                's3_path' => $s3Path,
                'cloudfront_url' => $url,
                'is_active' => true,
                'source' => 'uploaded',
            ]);

            $created++;
        }

        if ($created === 0 && !empty($errors)) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'No images uploaded. ' . implode(' ', $errors),
            ]);
        }

        $msg = "{$created} image" . ($created !== 1 ? 's' : '') . ' uploaded successfully.';
        if (!empty($errors)) {
            $msg .= ' ' . count($errors) . ' skipped: ' . implode(' ', $errors);
        }

        return redirect()->back()->with('flash', [
            'type' => $errors ? 'warning' : 'success',
            'message' => $msg,
        ]);
    }

    /**
     * Delete an image collateral (any source — AI, harvested, or uploaded).
     */
    public function destroy(ImageCollateral $imageCollateral)
    {
        $user = Auth::user();
        $customerId = $imageCollateral->campaign?->customer_id ?? $imageCollateral->strategy?->campaign?->customer_id;
        if (!$customerId || !$user->customers()->where('customers.id', $customerId)->exists()) {
            abort(403, 'Unauthorized action.');
        }

        if ($imageCollateral->s3_path) {
            StorageHelper::delete($imageCollateral->s3_path);
        }
        $imageCollateral->delete();

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Image deleted.',
        ]);
    }
}
