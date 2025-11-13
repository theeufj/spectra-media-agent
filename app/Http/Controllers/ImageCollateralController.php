<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateImage;
use App\Jobs\RefineImage;
use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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

        // Ensure the campaign belongs to the authenticated user.
        if ($campaign->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        // Ensure the strategy belongs to the campaign.
        if ($strategy->campaign_id !== $campaign->id) {
            abort(403, 'Strategy does not belong to this campaign.');
        }

        // Dispatch the job to handle the image generation in the background.
        GenerateImage::dispatch($campaign, $strategy);

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
        if ($imageCollateral->campaign->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        $contextImagePath = null;
        if ($request->hasFile('context_image')) {
            // Store the uploaded context image locally temporarily.
            // The job will clean it up after processing.
            $contextImagePath = $request->file('context_image')->store('temp_context_images');
        }

        // Dispatch the job to handle the refinement.
        RefineImage::dispatch($imageCollateral, $request->prompt, $contextImagePath);

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Image refinement has been queued. Please check back in a few moments.'
        ]);
    }
}
