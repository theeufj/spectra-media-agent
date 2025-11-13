<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateVideo;
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
        Log::info("Video generation requested for Campaign ID: {$campaign->id}, Strategy ID: {$strategy->id}");

        if ($campaign->user_id !== Auth::id() || $strategy->campaign_id !== $campaign->id) {
            abort(403, 'Unauthorized action.');
        }

        GenerateVideo::dispatch($campaign, $strategy);

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Video generation has been queued. This process can take several minutes. You will be notified upon completion.'
        ]);
    }
}
