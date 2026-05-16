<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAdCopy;
use App\Jobs\GenerateImage;
use App\Models\Campaign;
use App\Models\Strategy;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StrategyController extends Controller
{
    use AuthorizesRequests;

    /**
     * Show the form for creating a new strategy.
     * Redirects to campaign wizard since strategies always belong to campaigns.
     */
    public function create()
    {
        return redirect()->route('campaigns.wizard');
    }

    /**
     * Store a newly created strategy.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'campaign_id' => 'required|integer|exists:campaigns,id',
            'platform' => 'required|string|in:google,facebook,microsoft,linkedin',
            'campaign_type' => 'required|string|in:search,display,video,shopping,performance_max,demand_gen,local_services,app',
            'ad_copy_strategy' => 'required|string',
            'imagery_strategy' => 'required|string',
            'video_strategy' => 'required|string',
            'daily_budget' => 'nullable|numeric|min:1',
        ]);

        $campaign = Campaign::findOrFail($validated['campaign_id']);
        $customer = $request->user()->customers()->findOrFail(session('active_customer_id'));

        if ($campaign->customer_id !== $customer->id) {
            abort(403);
        }

        $strategy = Strategy::create($validated);

        return redirect()->route('campaigns.show', $campaign)
            ->with('success', 'Strategy created successfully.');
    }

    /**
     * Show the form for editing a strategy.
     */
    public function edit(Strategy $strategy)
    {
        $this->authorize('update', $strategy);

        $strategy->load('campaign');

        return Inertia::render('Strategies/Edit', [
            'strategy' => $strategy,
            'campaign' => $strategy->campaign,
        ]);
    }

    /**
     * approve marks a strategy as approved.
     *
     * @param Strategy $strategy The strategy model instance (route-model binding).
     * @return \Illuminate\Http\RedirectResponse
     */
    public function approve(Strategy $strategy)
    {
        $this->authorize('update', $strategy);

        $strategy->update(['status' => 'approved']);

        return back()->with('success', 'Strategy approved!');
    }

    /**
     * update modifies the content of a strategy.
     *
     * @param Request $request The incoming HTTP request.
     * @param Strategy $strategy The strategy model instance.
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Strategy $strategy)
    {
        $this->authorize('update', $strategy);

        $validated = $request->validate([
            'ad_copy_strategy' => 'required|string',
            'imagery_strategy' => 'required|string',
            'video_strategy' => 'required|string',
        ]);

        $strategy->update($validated);

        // If this strategy was already signed off, its collateral is now stale.
        // Delete existing ad copies and images and re-queue generation.
        // Videos are left untouched (expensive, slow — user can regenerate manually).
        if ($strategy->signed_off_at) {
            $campaign = $strategy->campaign;

            $strategy->adCopies()->delete();
            $strategy->imageCollaterals()->each(function ($img) {
                \App\Services\StorageHelper::delete($img->s3_path);
                $img->delete();
            });

            GenerateAdCopy::dispatch($campaign, $strategy, $strategy->platform)
                ->delay(now()->addSeconds(3));

            for ($i = 0; $i < 3; $i++) {
                GenerateImage::dispatch($campaign, $strategy)
                    ->delay(now()->addSeconds(10 + ($i * 10)));
            }

            return back()->with('success', 'Strategy updated — regenerating ad copy and images.');
        }

        return back()->with('success', 'Strategy updated!');
    }

    /**
     * Remove the specified strategy.
     */
    public function destroy(Strategy $strategy)
    {
        $this->authorize('update', $strategy);

        if ($strategy->signed_off_at) {
            return back()->with('error', 'Cannot delete a strategy that has been signed off.');
        }

        $campaign = $strategy->campaign;
        $strategy->delete();

        return redirect()->route('campaigns.show', $campaign)
            ->with('success', 'Strategy deleted.');
    }
}
