<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCampaignRequest;
use App\Jobs\GenerateStrategy;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CampaignController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $campaigns = Campaign::with(['strategies.adCopies', 'strategies.imageCollaterals', 'strategies.videoCollaterals'])->get();

        return Inertia::render('Campaigns/Index', [
            'campaigns' => $campaigns,
        ]);
    }

    /**
     * create is the handler for showing the campaign creation form.
     *
     * @return \Inertia\Response
     */
    public function create()
    {
        return Inertia::render('Campaigns/Create');
    }

    /**
     * store is the handler for creating a new campaign.
     *
     * @param StoreCampaignRequest $request The validated incoming HTTP request.
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(StoreCampaignRequest $request)
    {
        $validatedData = $request->validated();

        // Get the authenticated user
        $user = $request->user();

        // This will create a Stripe customer if one doesn't exist, or retrieve the existing one.
        $stripeCustomer = $user->createOrGetStripeCustomer();

        // Manually create the local customer record if it doesn't exist.
        // The `customer()` relationship method comes from the Billable trait.
        $customer = $user->customer()->firstOrCreate([
            'stripe_id' => $stripeCustomer->id,
        ]);

        // Add the customer_id to the validated data
        $validatedData['customer_id'] = $customer->id;

        // Create the campaign for the user
        $campaign = $user->campaigns()->create($validatedData);

        GenerateStrategy::dispatch($campaign);

        // Redirect the user to the new strategy review page.
        return redirect()->route('campaigns.show', $campaign);
    }

    /**
     * show is the handler for displaying a campaign and its generated strategies.
     *
     * @param Campaign $campaign The campaign model instance (route-model binding).
     * @return \Inertia\Response
     */
    public function show(Campaign $campaign)
    {
        // We use `load('strategies')` to eager-load the relationship.
        // This is more efficient than lazy-loading and prevents the "N+1 query problem".
        // It's similar to a `Preload` in GORM or `JOIN` in a raw SQL query.
        $campaign->load('strategies');

        return Inertia::render('Campaigns/Show', [
            'campaign' => $campaign,
        ]);
    }

    /**
     * signOffStrategy is the handler for marking a strategy as signed off.
     *
     * @param Campaign $campaign The campaign model instance.
     * @param \App\Models\Strategy $strategy The strategy model instance.
     * @return \Illuminate\Http\RedirectResponse
     */
    public function signOffStrategy(Campaign $campaign, \App\Models\Strategy $strategy)
    {
        // Ensure the strategy belongs to the campaign and the campaign belongs to the authenticated user.
        if ($campaign->user_id !== Auth::id() || $strategy->campaign_id !== $campaign->id) {
            abort(403, 'Unauthorized action.');
        }

        $strategy->update([
            'signed_off_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Strategy signed off successfully!');
    }

    /**
     * signOffAllStrategies is the handler for marking all strategies of a campaign as signed off.
     *
     * @param Campaign $campaign The campaign model instance.
     * @return \Illuminate\Http\RedirectResponse
     */
    public function signOffAllStrategies(Campaign $campaign)
    {
        // Ensure the campaign belongs to the authenticated user.
        if ($campaign->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        // Update all strategies that have not been signed off yet.
        $campaign->strategies()->whereNull('signed_off_at')->update([
            'signed_off_at' => now(),
        ]);

        return redirect()->route('campaigns.show', $campaign)->with('success', 'All strategies have been signed off!');
    }
}
