<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCampaignRequest;
use App\Jobs\GenerateStrategy;
use App\Jobs\GenerateCampaignCollateral;
use App\Models\Campaign;
use App\Models\Strategy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CampaignController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $customer = $request->user()->customers()->findOrFail(session('active_customer_id'));
        $campaigns = $customer->campaigns()->with(['strategies' => function ($query) {
            $query->withCount(['adCopies', 'imageCollaterals', 'videoCollaterals']);
        }])->get();

        return Inertia::render('Campaigns/Index', [
            'campaigns' => $campaigns,
        ]);
    }

    /**
     * create is the handler for showing the campaign creation form.
     */
    public function create()
    {
        return Inertia::render('Campaigns/Create');
    }

    /**
     * store is the handler for creating a new campaign.
     */
    public function store(StoreCampaignRequest $request)
    {
        $customer = $request->user()->customers()->findOrFail(session('active_customer_id'));
        $campaign = $customer->campaigns()->create($request->validated());

        if ($request->has('selected_pages')) {
            $campaign->pages()->attach($request->input('selected_pages'));
        }

        // Mark that we're about to start generating strategies
        $campaign->update(['strategy_generation_started_at' => now()]);
        
        GenerateStrategy::dispatch($campaign);

        return redirect()->route('campaigns.show', $campaign);
    }

    /**
     * show is the handler for displaying a campaign and its generated strategies.
     */
    public function show(Request $request, Campaign $campaign)
    {
        $customer = $request->user()->customers()->findOrFail(session('active_customer_id'));
        if ($campaign->customer_id !== $customer->id) {
            abort(403);
        }

        $campaign->load('strategies');
        
        // Add generation status
        $campaignData = $campaign->toArray();
        $campaignData['is_generating_strategies'] = $campaign->isGeneratingStrategies();

        return Inertia::render('Campaigns/Show', [
            'campaign' => $campaignData,
        ]);
    }

    /**
     * signOffStrategy is the handler for marking a strategy as signed off.
     */
    public function signOffStrategy(Request $request, Campaign $campaign, Strategy $strategy)
    {
        $customer = $request->user()->customers()->findOrFail(session('active_customer_id'));
        if ($campaign->customer_id !== $customer->id || $strategy->campaign_id !== $campaign->id) {
            abort(403);
        }

        $strategy->update(['signed_off_at' => now()]);
        
        // Reload campaign with strategies to get fresh data
        $campaign->load('strategies');
        $campaignData = $campaign->toArray();
        $campaignData['is_generating_strategies'] = $campaign->isGeneratingStrategies();

        return back()->with('success', 'Strategy signed off successfully!');
    }

    /**
     * signOffAllStrategies is the handler for marking all strategies of a campaign as signed off.
     */
    public function signOffAllStrategies(Request $request, Campaign $campaign)
    {
        $customer = $request->user()->customers()->findOrFail(session('active_customer_id'));
        if ($campaign->customer_id !== $customer->id) {
            abort(403);
        }

        $campaign->strategies()->whereNull('signed_off_at')->update(['signed_off_at' => now()]);

        // Dispatch collateral generation job
        GenerateCampaignCollateral::dispatch($campaign, $request->user()->id);

        return back()->with('success', 'All strategies have been signed off! We are generating your collateral now.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Campaign $campaign)
    {
        $customer = $request->user()->customers()->findOrFail(session('active_customer_id'));
        if ($campaign->customer_id !== $customer->id) {
            abort(403);
        }

        $campaign->delete();

        return redirect()->route('campaigns.index')->with('success', 'Campaign deleted successfully.');
    }

    /**
     * Get the performance data for a campaign.
     */
    public function performance(Request $request, Campaign $campaign)
    {
        $customer = $request->user()->customers()->findOrFail(session('active_customer_id'));
        if ($campaign->customer_id !== $customer->id) {
            abort(403);
        }

        // ... existing performance logic ...
    }

    /**
     * API endpoint to get campaign data with strategies (for polling).
     */
    public function apiShow(Request $request, Campaign $campaign)
    {
        // Check if user has access to this campaign through any of their customers
        $user = $request->user();
        $hasAccess = $user->customers()->where('customers.id', $campaign->customer_id)->exists();
        
        if (!$hasAccess) {
            abort(403, 'You do not have access to this campaign.');
        }

        $campaign->load('strategies');
        
        // Add generation status to response
        $campaignData = $campaign->toArray();
        $campaignData['is_generating_strategies'] = $campaign->isGeneratingStrategies();

        return response()->json($campaignData);
    }
}
