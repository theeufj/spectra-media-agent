<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAdCopy;
use App\Models\Campaign;
use App\Models\Strategy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdCopyController extends Controller
{
    /**
     * Store a newly generated ad copy in storage.
     *
     * @param Request $request The incoming HTTP request.
     * @param Campaign $campaign The campaign model instance.
     * @param Strategy $strategy The strategy model instance.
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, Campaign $campaign, Strategy $strategy)
    {
        // Log the start of the ad copy generation process.
        Log::info("Ad copy generation requested for Campaign ID: {$campaign->id}, Strategy ID: {$strategy->id}, Platform: {$request->input('platform')}");

        // Ensure the campaign belongs to a customer that the authenticated user is part of.
        $user = Auth::user();
        if (!$user->customers()->where('customers.id', $campaign->customer_id)->exists()) {
            abort(403, 'Unauthorized action.');
        }

        // Ensure the strategy belongs to the campaign.
        if ($strategy->campaign_id !== $campaign->id) {
            abort(403, 'Strategy does not belong to this campaign.');
        }

        // Ensure the strategy has been signed off.
        if (is_null($strategy->signed_off_at)) {
            return redirect()->back()->with('error', 'Strategy must be signed off before generating ad copy.');
        }

        $platform = $request->input('platform');

        if (empty($platform)) {
            return redirect()->back()->with('error', 'Platform is required for ad copy generation.');
        }

        // Dispatch the job to handle the generation in the background.
        GenerateAdCopy::dispatch($campaign, $strategy, $platform);

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Ad copy generation has been queued. You will be notified upon completion.'
        ]);
    }
}
