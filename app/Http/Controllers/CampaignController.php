<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCampaignRequest;
use App\Jobs\GenerateCampaignCollateral;
use App\Jobs\GenerateStrategy;
use App\Jobs\GenerateStrategyCollateral;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\ActivityLogger;
use App\Services\StorageHelper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CampaignController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return redirect()->route('quick-start');
        }
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
        return redirect()->route('campaigns.wizard');
    }

    /**
     * Building the campaign is what a one-time setup customer paid us not to do.
     *
     * They reached the wizard anyway — the shell's "New Campaign" button is
     * shown to everyone — and it told them "No ad platform sub-accounts are set
     * up yet. Contact us to get your account configured." That is the single
     * worst sentence we could show this person: their account is configured on
     * payment, automatically, and being told to contact us is the intimidation
     * the US$999 exists to remove.
     *
     * So send them to the step that is actually theirs. Hiding the button is
     * not enough on its own — a bookmark, the onboarding tour or a typed URL
     * all land here.
     */
    private function setupOnlyDetour(Customer $customer): ?RedirectResponse
    {
        if ($customer->service_type !== 'setup_only') {
            return null;
        }

        if (! $customer->isPaidSetupOnly()) {
            // The journey's own payment step links here; setup-fee.checkout is
            // a POST and cannot be redirected to.
            return redirect()->route('subscription.pricing')->with('flash', [
                'type' => 'info',
                'message' => 'We build the campaign for you — that is what the one-time setup covers. It starts as soon as the fee is paid.',
            ]);
        }

        $campaign = $customer->campaigns()->latest('id')->first();

        if ($campaign) {
            return redirect()->route('campaigns.show', $campaign)->with('flash', [
                'type' => 'info',
                'message' => 'This is the campaign we built for you. Review it, then create the ads when you are happy.',
            ]);
        }

        return redirect()->route('dashboard')->with('flash', [
            'type' => 'info',
            'message' => 'We are building your campaign now — nothing for you to do yet. We will email you the moment it is ready to review.',
        ]);
    }

    /**
     * wizard is the handler for showing the campaign creation wizard.
     */
    public function wizard(Request $request)
    {
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return redirect()->route('quick-start');
        }

        if ($redirect = $this->setupOnlyDetour($customer)) {
            return $redirect;
        }

        // Load available pages for the customer
        $pages = $customer->pages()
            ->orderBy('created_at', 'desc')
            ->get(['id', 'url', 'title', 'meta_description'])
            ->toArray();

        // If no customer_pages exist, backfill from knowledge_bases
        if (empty($pages)) {
            $userIds = $customer->users()->pluck('users.id');
            $kbEntries = \App\Models\KnowledgeBase::where('customer_id', $customer->id)
                ->whereNotNull('url')
                ->select('url')
                ->distinct()
                ->get();

            foreach ($kbEntries as $kb) {
                \App\Models\CustomerPage::firstOrCreate(
                    ['customer_id' => $customer->id, 'url' => $kb->url],
                    ['title' => basename(parse_url($kb->url, PHP_URL_PATH)) ?: parse_url($kb->url, PHP_URL_HOST)]
                );
            }

            // Re-query after backfill
            $pages = $customer->pages()
                ->orderBy('created_at', 'desc')
                ->get(['id', 'url', 'title', 'meta_description'])
                ->toArray();
        }

        // Load brand guidelines for pre-filling
        $brandGuideline = \App\Models\BrandGuideline::where('customer_id', $customer->id)
            ->latest('extracted_at')
            ->first();

        // Compute selectable platforms: intersection of system-enabled, plan-allowed, and customer-configured
        $enabledPlatforms = \App\Models\EnabledPlatform::getEnabledPlatformNames();
        // The account's plan, not the logged-in teammate's. Read from the user
        // it also short-circuited to all four platforms for anyone holding the
        // global admin role, so an admin opening a customer's wizard saw
        // platforms that customer cannot deploy to.
        $allowedPlatforms = $customer->allowedPlatforms();
        $configuredPlatforms = $customer->configuredPlatforms();

        /*
           Selectable is what the product supports, not what this plan has
           already bought.

           Gating the choice by plan meant a customer had to buy before finding
           out what the purchase let them pick — Facebook greyed out with
           "upgrade your plan to unlock" and nothing saying which upgrade or
           what it costs. The wizard now asks where they want to advertise and
           the payment step answers what that costs, which is the same
           information in the order a buyer can act on.

           Entitlement has not moved: DeployCampaign still filters strategies to
           allowedPlatforms and marks the rest skipped_plan. What changed is
           that the customer learns the price of what they want instead of
           being refused the choice.

           The previous note on this block, still true and the other half of
           why it was stuck:

           Intersecting with configuredPlatforms locked the funnel shut. The
           sub-account is created on deploy intent — confirmBudget() dispatches
           ProvisionGoogleAdsAccount, deliberately, so tire-kickers do not mint
           real Google Ads accounts at signup. But confirming a budget needs a
           campaign, creating a campaign needs to pass this step, and this step
           needed an account that only exists after the budget is confirmed:

               no campaign -> no budget -> no account -> no platform -> no campaign

           The only way through was GenerateFirstCampaign writing a campaign
           without the wizard, which makes that job the sole entrance to the
           managed product rather than the bonus it is described as. An account
           it declines is stuck for good on "contact your admin to set up ad
           platform accounts" — nobody's job, and the reason every real signup
           has stopped at or before budget_confirmed.

           Entitlement still applies: a plan that does not include Facebook
           still cannot pick it. What no longer applies is a chicken-and-egg
           check on infrastructure we create later anyway.
        */
        $selectablePlatforms = array_values(array_map('strtolower', $enabledPlatforms));

        return Inertia::render('Campaigns/CreateWizard', [
            'pages' => $pages,
            'brandGuideline' => $brandGuideline,
            'allowedPlatforms' => $allowedPlatforms,
            'selectablePlatforms' => $selectablePlatforms,
            'configuredPlatforms' => $configuredPlatforms,
            /*
               Which platforms this product supports at all, as distinct from
               which this plan includes.

               Microsoft and LinkedIn are not enabled system-wide, and the
               wizard told customers to "upgrade your plan to unlock" them —
               selling an upgrade that would not deliver them, because no plan
               can. The two reasons need telling apart wherever they are shown.
            */
            'enabledPlatforms' => array_map('strtolower', $enabledPlatforms),
            /*
               What each possible choice costs, so the page can price a
               selection as it is made rather than refusing it.

               Keyed by platform count because that is what separates the tiers:
               one platform is Starter, more than one is Growth. Sent as plain
               figures so the wizard never has to know the pricing rules.
            */
            'planForPlatforms' => collect([1, 2])
                ->mapWithKeys(function (int $count) {
                    $sample = array_slice(['google', 'facebook', 'microsoft', 'linkedin'], 0, $count);
                    $plan = \App\Models\Plan::cheapestFor($sample);

                    return [$count => $plan ? [
                        'name' => $plan->name,
                        'price' => $plan->price_cents / 100,
                        'interval' => $plan->billing_interval,
                    ] : null];
                })
                ->all(),
            /*
               Why a platform is unavailable, so the page can say something
               true instead of guessing.

               A one-time-setup customer saw "Contact admin to set up" against
               Google — which is nobody's job, the sub-account is created the
               moment they pay — and "Upgrade your plan to unlock" against
               Facebook, which they cannot buy and which the US$999 never
               included.
            */
            'setupOnly' => $customer->service_type === 'setup_only',
            'setupFeePaid' => $customer->setup_fee_paid_at !== null,
        ]);
    }

    /**
     * deploymentStatus is the handler for showing the deployment status page.
     */
    public function deploymentStatus(Request $request, Campaign $campaign)
    {
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return redirect()->route('quick-start');
        }
        if ($campaign->customer_id !== $customer->id) {
            abort(403);
        }

        // Load strategies with deployment information
        $campaign->load(['strategies' => function ($query) {
            $query->withCount(['adCopies', 'imageCollaterals', 'videoCollaterals']);
        }]);

        // Get any recent deployment jobs for this campaign
        $deployments = $campaign->strategies->map(function ($strategy) {
            return [
                'id' => $strategy->id,
                'platform' => $strategy->platform,
                'status' => $strategy->deployment_status ?? 'pending',
                'deployed_at' => $strategy->deployed_at,
                'error_message' => $strategy->deployment_error,
                'ad_copies_count' => $strategy->ad_copies_count,
                'images_count' => $strategy->image_collaterals_count,
                'videos_count' => $strategy->video_collaterals_count,
            ];
        });

        return Inertia::render('Campaigns/DeploymentStatus', [
            'campaign' => $campaign->toArray(),
            'deployments' => $deployments,
            /*
               A one-time setup campaign does not begin serving tomorrow, or on
               any day we choose. It is paused as it deploys and its owner
               starts it. The success panel told them the opposite on the very
               screen where they watched it happen.
            */
            'setupOnly' => $campaign->customer?->service_type === 'setup_only',
        ]);
    }

    /**
     * store is the handler for creating a new campaign.
     */
    /**
     * Accept (or change) the daily budget on an auto-generated campaign.
     *
     * This is what turns the model's suggestion into a figure the customer owns.
     * Until it runs, budget_confirmed_at is null and DeploymentController
     * refuses to deploy — because deployment charges seven days of this number
     * up front.
     */
    public function confirmBudget(Request $request, Campaign $campaign)
    {
        $this->authorize('update', $campaign);

        $validated = $request->validate([
            'daily_budget' => 'required|numeric|min:5|max:10000',
        ]);

        $daily = round((float) $validated['daily_budget'], 2);

        $campaign->update([
            'daily_budget' => $daily,
            'approved_daily_budget' => $daily,
            // Kept in step with the daily figure, so the seven-day prepay and
            // the campaign's own total cannot disagree.
            'total_budget' => round($daily * 7, 2),
            'budget_confirmed_at' => now(),
        ]);

        ActivityLogger::campaign('updated', $campaign, ['daily_budget' => $daily, 'budget_confirmed' => true]);

        // Deploy-intent: this is the moment a real Google Ads sub-account is
        // worth creating (provisioning at signup minted accounts for
        // tire-kickers that had to be cancelled by hand).
        \App\Jobs\ProvisionGoogleAdsAccount::dispatchIfNeeded($campaign->customer);

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Budget confirmed. You can deploy whenever you\'re ready.',
        ]);
    }

    public function store(StoreCampaignRequest $request)
    {
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return redirect()->route('quick-start');
        }

        // THE COST CEILING FOR FREE ACCOUNTS.
        //
        // This route used to sit behind the `subscribed` middleware, so this
        // branch could never run — a guest was redirected to the pricing page
        // before reaching it. Now that building a campaign is free, this is the
        // only thing between an unsubscribed account and unbounded Gemini
        // spend: creating a campaign dispatches GenerateStrategy, and that
        // calls a paid model.
        //
        // One campaign is enough to see what the product produces for your own
        // website, which is the whole point of letting it be free. Loosening
        // this without another ceiling in front of GenerateStrategy would let
        // one account run up a bill unattended.
        if ($customer->resolvePlan()->slug === 'free') {
            $existingCount = $customer->campaigns()->count();
            if ($existingCount >= 1) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'Your free campaign is ready to review. Subscribe to deploy it or create more.',
                ]);
            }
        }

        $validated = $request->validated();

        // Extract keywords and files before creating campaign (not campaign columns)
        $keywords = $validated['keywords'] ?? [];
        $uploadedImages = $validated['images'] ?? [];
        $seedImages = $validated['seed_images'] ?? [];
        $uploadedVideos = $validated['videos'] ?? [];
        unset($validated['keywords'], $validated['images'], $validated['seed_images'], $validated['videos']);

        // Calculate daily_budget if not provided
        if (empty($validated['daily_budget']) && ! empty($validated['total_budget'])) {
            $startDate = \Carbon\Carbon::parse($validated['start_date']);
            $endDate = \Carbon\Carbon::parse($validated['end_date']);
            $days = max(1, $startDate->diffInDays($endDate) + 1); // +1 to include both start and end days
            $validated['daily_budget'] = round($validated['total_budget'] / $days, 2);
        }

        $validated['approved_daily_budget'] = $validated['daily_budget'] ?? null;
        $campaign = $customer->campaigns()->create($validated);

        // The activity log knew 22 action types and only ever recorded two:
        // login and logout. Everything a customer actually does was invisible,
        // which is why the page read as a login history.
        ActivityLogger::campaign('created', $campaign, [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
        ]);

        if ($request->has('selected_pages')) {
            $campaign->pages()->attach($request->input('selected_pages'));
        }

        // Store user-selected keywords
        if (! empty($keywords)) {
            foreach ($keywords as $kw) {
                \App\Models\Keyword::updateOrCreate(
                    [
                        'customer_id' => $customer->id,
                        'keyword_text' => $kw['text'],
                        'match_type' => $kw['match_type'],
                        'campaign_id' => $campaign->id,
                    ],
                    [
                        'status' => 'active',
                        'source' => 'wizard',
                        'avg_monthly_searches' => $kw['avg_monthly_searches'] ?? null,
                        'competition_index' => $kw['competition_index'] ?? null,
                        'intent' => $kw['intent'] ?? null,
                        'cluster' => $kw['cluster'] ?? null,
                        'funnel_stage' => $kw['funnel_stage'] ?? null,
                        'added_by' => $request->user()->id,
                    ]
                );
            }

            // Also store on campaign JSON for quick access by strategy generation
            $campaign->update(['keywords' => $keywords]);
        }

        // Upload any user-provided images from the wizard
        $defaultPlatform = $campaign->platforms[0] ?? 'google';
        foreach ($uploadedImages as $file) {
            $ext = $file->getClientOriginalExtension() ?: 'jpg';
            $path = 'collateral/images/'.$campaign->id.'/'.Str::uuid().'.'.$ext;
            [$s3Path, $url] = StorageHelper::put($path, file_get_contents($file->getPathname()), $file->getMimeType() ?: 'image/jpeg');
            ImageCollateral::create([
                'campaign_id' => $campaign->id,
                'strategy_id' => null,
                'platform' => $defaultPlatform,
                's3_path' => $s3Path,
                'cloudfront_url' => $url,
                'is_active' => true,
                'source' => 'uploaded',
            ]);
        }

        // Upload seed images — these are used as visual reference for AI-generated ads
        foreach ($seedImages as $file) {
            $ext = $file->getClientOriginalExtension() ?: 'jpg';
            $path = 'collateral/images/'.$campaign->id.'/'.Str::uuid().'.'.$ext;
            [$s3Path, $url] = StorageHelper::put($path, file_get_contents($file->getPathname()), $file->getMimeType() ?: 'image/jpeg');
            ImageCollateral::create([
                'campaign_id' => $campaign->id,
                'strategy_id' => null,
                'platform' => $defaultPlatform,
                's3_path' => $s3Path,
                'cloudfront_url' => $url,
                'is_active' => true,
                'is_seed' => true,
                // Inspiration only. Deploy queries now adopt campaign-level
                // rows, so without this the raw seed uploads would run as ads.
                'should_deploy' => false,
                'source' => 'uploaded',
            ]);
        }

        // Upload any user-provided videos from the wizard
        foreach ($uploadedVideos as $file) {
            $ext = $file->getClientOriginalExtension() ?: 'mp4';
            $path = 'collateral/videos/'.$campaign->id.'/'.Str::uuid().'.'.$ext;
            [$s3Path, $url] = StorageHelper::put($path, file_get_contents($file->getPathname()), $file->getMimeType() ?: 'video/mp4');
            VideoCollateral::create([
                'campaign_id' => $campaign->id,
                'strategy_id' => null,
                'platform' => $campaign->platforms[0] ?? 'google',
                's3_path' => $s3Path,
                'cloudfront_url' => $url,
                'status' => 'completed',
                'is_active' => true,
                'source' => 'uploaded',
            ]);
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
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return redirect()->route('quick-start');
        }
        if ($campaign->customer_id !== $customer->id) {
            abort(403);
        }

        // Load strategies with collateral counts
        $campaign->load(['strategies' => function ($query) {
            $query->withCount(['adCopies', 'imageCollaterals', 'videoCollaterals']);
        }]);

        // Calculate total collateral counts
        $totalAdCopies = $campaign->strategies->sum('ad_copies_count');
        $totalImages = $campaign->strategies->sum('image_collaterals_count');
        $totalVideos = $campaign->strategies->sum('video_collaterals_count');

        // Add generation status
        $campaignData = $campaign->toArray();
        $campaignData['is_generating_strategies'] = $campaign->isGeneratingStrategies();
        $campaignData['collateral_summary'] = [
            'ad_copies' => $totalAdCopies,
            'images' => $totalImages,
            'videos' => $totalVideos,
            'total' => $totalAdCopies + $totalImages + $totalVideos,
        ];
        // The budget panel states what will be charged, so it needs the
        // currency. Sent as a scalar rather than loading the whole customer.
        $campaignData['currency_code'] = $customer->currency_code ?? 'USD';

        return Inertia::render('Campaigns/Show', [
            'campaign' => $campaignData,
            // Regeneration is a paid action; the page needs to say so instead
            // of letting a free user click into an unexplained pricing
            // redirect.
            'canRegenerate' => $request->user()->hasSubscriptionAccess($customer),
            // Surfaced during onboarding: install tracking before the first
            // launch so conversions are counted from day one.
            'conversionTracking' => [
                'container_id' => $customer->gtm_container_id,
                'installed' => (bool) $customer->gtm_installed,
                'setup_url' => route('customers.gtm.setup', $customer, false),
            ],
            /*
               Whether we are the ones who bill for the ad spend.

               The budget panel told every customer "You'll be charged AUD
               280.00 when you deploy — seven days up front. After that we top
               up as you spend." For a self-funded account that is simply
               untrue: their card is on the Google account, Google charges them
               directly, and DeployCampaign skips ad-spend credit for them
               entirely. Worst of all on the one-time setup, which was sold as
               one payment and nothing recurring — the last screen before the
               ads are created promised a second charge and an ongoing one.
            */
            'selfFunded' => $customer->isSelfFundedAds(),
            'setupOnly' => $customer->service_type === 'setup_only',
        ]);
    }

    /**
     * signOffStrategy is the handler for marking a strategy as signed off.
     */
    public function signOffStrategy(Request $request, Campaign $campaign, Strategy $strategy)
    {
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return redirect()->route('quick-start');
        }
        if ($campaign->customer_id !== $customer->id || $strategy->campaign_id !== $campaign->id) {
            abort(403);
        }

        $strategy->update(['signed_off_at' => now()]);

        // Dispatch collateral generation for this strategy
        GenerateStrategyCollateral::dispatch($campaign, $strategy, $request->user()->id);

        // Reload campaign with strategies to get fresh data
        $campaign->load('strategies');
        $campaignData = $campaign->toArray();
        $campaignData['is_generating_strategies'] = $campaign->isGeneratingStrategies();

        return back()->with('success', 'Strategy signed off! Generating collateral now...');
    }

    /**
     * signOffAllStrategies is the handler for marking all strategies of a campaign as signed off.
     */
    public function signOffAllStrategies(Request $request, Campaign $campaign)
    {
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return redirect()->route('quick-start');
        }
        if ($campaign->customer_id !== $customer->id) {
            abort(403);
        }

        $campaign->strategies()->whereNull('signed_off_at')->update(['signed_off_at' => now()]);

        // Dispatch collateral generation job
        GenerateCampaignCollateral::dispatch($campaign, $request->user()->id);

        return back()->with('success', 'All strategies have been signed off! We are generating your collateral now.');
    }

    /**
     * regenerateStrategies deletes existing strategies and re-dispatches the generation job.
     */
    /**
     * Remove every generated asset belonging to a campaign, files included.
     */
    public function regenerateStrategies(Request $request, Campaign $campaign)
    {
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return redirect()->route('quick-start');
        }
        if ($campaign->customer_id !== $customer->id) {
            abort(403);
        }

        $force = $request->boolean('force', false);

        // A live campaign's strategy rows are what billing and reconciliation
        // read against — deleting them under running ads orphans the platform
        // campaigns. Pause first, then rebuild.
        if ($this->hasLiveDeployments($campaign)) {
            return back()->with('error', 'This campaign has ads running. Pause the campaign before regenerating its strategies.');
        }

        if ($campaign->strategies()->whereNotNull('signed_off_at')->exists()) {
            if (! $force) {
                return back()->with('error', 'Some strategies are already signed off. Use force regeneration to revert sign-offs and start over.');
            }
            // Force regeneration reverts the sign-offs; the collateral goes
            // below, for the whole campaign rather than for these strategies.
        }

        // Retain the reviewed version until the replacement validates and commits.

        // Reset generation state and re-dispatch
        $campaign->update([
            'strategy_generation_started_at' => now(),
            'strategy_generation_error' => null,
        ]);

        GenerateStrategy::dispatch($campaign);

        return back()->with('success', 'Regenerating strategies...');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Campaign $campaign)
    {
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return redirect()->route('quick-start');
        }
        if ($campaign->customer_id !== $customer->id) {
            abort(403);
        }

        // Deleting the local row does NOT stop the platform campaigns — the
        // ads keep running and spending with nothing left to reconcile or
        // bill against. Deletion is for campaigns that aren't live.
        if ($this->hasLiveDeployments($campaign)) {
            return back()->with('error', 'This campaign has ads running on the platforms. Pause it first, then delete — otherwise the ads would keep spending with no record on our side.');
        }

        $campaign->delete();

        return redirect()->route('campaigns.index')->with('success', 'Campaign deleted successfully.');
    }

    /**
     * Does this campaign have ads live (or plausibly live) on any platform?
     */
    private function hasLiveDeployments(Campaign $campaign): bool
    {
        return $campaign->status === \App\Enums\CampaignStatus::Active
            || $campaign->strategies()
                ->whereIn('deployment_status', array_merge(Strategy::DEPLOYED_STATUSES, ['deploying', 'deploy_unverified']))
                ->exists();
    }

    /**
     * Get the performance data for a campaign.
     * Allows access for: campaign owner OR admin users
     */
    public function performance(Request $request, Campaign $campaign)
    {
        $user = $request->user();

        // Check if user is admin OR owns this campaign
        $isAdmin = $user->hasRole('admin');
        $isOwner = $user->can('view', $campaign);

        if (! $isAdmin && ! $isOwner) {
            abort(403, 'You do not have access to this campaign.');
        }

        // Get date range from request
        $startDate = $request->input('start_date', now()->subDays(30)->format('Y-m-d'));
        $endDate = $request->input('end_date', now()->format('Y-m-d'));

        $emptyResponse = [
            'summary' => [
                'total_spend' => 0,
                'total_clicks' => 0,
                'impressions' => 0,
                'conversions' => 0,
                'average_ctr' => 0,
                'average_cpc' => 0,
                'average_cpa' => 0,
            ],
            'daily_data' => [],
        ];

        // Get Google Ads connection
        $connection = $campaign->customer->users()->first()?->connections()
            ->where('platform', 'google_ads')
            ->first();

        if (! $connection || ! $campaign->google_ads_campaign_id) {
            // Fall back to stored performance data if available
            $dailyData = $this->getStoredDailyData($campaign, $startDate, $endDate);
            if ($dailyData->isNotEmpty()) {
                return response()->json([
                    'summary' => $this->summarizeStoredData($dailyData),
                    'daily_data' => $this->formatDailyData($dailyData),
                ]);
            }

            return response()->json(array_merge($emptyResponse, [
                'message' => 'No Google Ads connection or campaign not deployed',
            ]));
        }

        try {
            // The service authenticates from the platform MCC and takes the
            // Customer; these three connection strings were a per-customer OAuth
            // shape this platform does not use, and passing them where a Customer
            // is required is a TypeError the moment a customer has a google_ads
            // connection row. The account queried is unchanged.
            $service = new \App\Services\GoogleAds\CommonServices\GetCampaignPerformance(
                $campaign->customer
            );

            $resourceName = $campaign->googleAdsResourceName();
            $metrics = $service($connection->platform_user_id, $resourceName, 'LAST_30_DAYS');

            // Get daily data from stored records
            $dailyData = $this->getStoredDailyData($campaign, $startDate, $endDate);

            if (! $metrics) {
                if ($dailyData->isNotEmpty()) {
                    return response()->json([
                        'summary' => $this->summarizeStoredData($dailyData),
                        'daily_data' => $this->formatDailyData($dailyData),
                    ]);
                }

                return response()->json(array_merge($emptyResponse, [
                    'message' => 'No performance data available yet',
                ]));
            }

            return response()->json([
                'summary' => [
                    'total_spend' => round($metrics['cost_micros'] / 1000000, 2),
                    'total_clicks' => $metrics['clicks'],
                    'impressions' => $metrics['impressions'],
                    'conversions' => $metrics['conversions'],
                    'average_ctr' => round($metrics['ctr'] * 100, 2),
                    'average_cpc' => round($metrics['average_cpc'] / 1000000, 2),
                    'average_cpa' => round($metrics['cost_per_conversion'] / 1000000, 2),
                ],
                'daily_data' => $this->formatDailyData($dailyData),
            ]);

        } catch (\Throwable $e) {
            report($e);
            \Log::error("Failed to fetch performance for campaign {$campaign->id}: ".$e->getMessage());

            // Fall back to stored data on API error
            $dailyData = $this->getStoredDailyData($campaign, $startDate, $endDate);
            if ($dailyData->isNotEmpty()) {
                return response()->json([
                    'summary' => $this->summarizeStoredData($dailyData),
                    'daily_data' => $this->formatDailyData($dailyData),
                    'error' => 'Live data unavailable, showing cached data',
                ]);
            }

            return response()->json(array_merge($emptyResponse, [
                'error' => 'Failed to fetch performance data',
            ]));
        }
    }

    /**
     * Get stored daily performance data from the database.
     */
    private function getStoredDailyData(Campaign $campaign, string $startDate, string $endDate)
    {
        return \App\Models\GoogleAdsPerformanceData::where('campaign_id', $campaign->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->get();
    }

    /**
     * Summarize stored daily data into aggregate stats.
     */
    private function summarizeStoredData($dailyData): array
    {
        $totalClicks = $dailyData->sum('clicks');
        $totalImpressions = $dailyData->sum('impressions');
        $totalCost = $dailyData->sum('cost');
        $totalConversions = $dailyData->sum('conversions');

        return [
            'total_spend' => round($totalCost, 2),
            'total_clicks' => $totalClicks,
            'impressions' => $totalImpressions,
            'conversions' => $totalConversions,
            'average_ctr' => $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0,
            'average_cpc' => $totalClicks > 0 ? round($totalCost / $totalClicks, 2) : 0,
            'average_cpa' => $totalConversions > 0 ? round($totalCost / $totalConversions, 2) : 0,
        ];
    }

    /**
     * Format daily data as date-keyed object for the chart.
     */
    private function formatDailyData($dailyData): array
    {
        $formatted = [];
        foreach ($dailyData as $row) {
            $formatted[$row->date] = [
                'impressions' => $row->impressions,
                'clicks' => $row->clicks,
                'cost' => $row->cost,
                'conversions' => $row->conversions,
            ];
        }

        return (object) $formatted;
    }

    /**
     * API endpoint to get campaign data with strategies (for polling).
     */
    public function apiShow(Request $request, Campaign $campaign)
    {
        // Check if user has access to this campaign through any of their customers
        $user = $request->user();
        $hasAccess = $user->can('view', $campaign);

        if (! $hasAccess) {
            abort(403, 'You do not have access to this campaign.');
        }

        /*
         * With the counts, because this endpoint exists to answer "has the
         * collateral arrived yet".
         *
         * It loaded bare strategies, so every strategy came back with no
         * *_count fields at all. The page reads them as zero, concludes the
         * work is still running, and polls forever: a campaign whose images had
         * finished minutes earlier sat on "Generating your collateral... this
         * usually takes 1-2 minutes" indefinitely, and the auto-advance to the
         * collateral page never fired because its condition could never be met.
         *
         * The Inertia endpoint for this page has always loaded them, which is
         * how the server render looked right and only the polling was wrong.
         */
        $campaign->load(['strategies' => fn ($q) => $q->withCount(['adCopies', 'imageCollaterals', 'videoCollaterals'])]);

        // Add generation status to response
        $campaignData = $campaign->toArray();
        $campaignData['is_generating_strategies'] = $campaign->isGeneratingStrategies();

        return response()->json($campaignData);
    }

    /**
     * API endpoint to get deployment status for a campaign (for polling).
     */
    public function apiDeploymentStatus(Request $request, Campaign $campaign)
    {
        // Check if user has access to this campaign
        $user = $request->user();
        $hasAccess = $user->can('view', $campaign);

        if (! $hasAccess) {
            abort(403, 'You do not have access to this campaign.');
        }

        // Load strategies with deployment information
        $campaign->load(['strategies' => function ($query) {
            $query->withCount(['adCopies', 'imageCollaterals', 'videoCollaterals']);
        }]);

        $deployments = $campaign->strategies->map(function ($strategy) {
            return [
                'id' => $strategy->id,
                'platform' => $strategy->platform,
                'status' => $strategy->deployment_status ?? 'pending',
                'deployed_at' => $strategy->deployed_at,
                'error_message' => $strategy->deployment_error,
                'ad_copies_count' => $strategy->ad_copies_count,
                'images_count' => $strategy->image_collaterals_count,
                'videos_count' => $strategy->video_collaterals_count,
                'progress' => $this->calculateDeploymentProgress($strategy),
            ];
        });

        // Calculate overall progress
        $totalSteps = $campaign->strategies->count() * 4; // Each strategy has 4 deployment steps
        $completedSteps = $deployments->sum('progress');
        $overallProgress = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;

        return response()->json([
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status->value,
            ],
            'deployments' => $deployments,
            'overall_progress' => $overallProgress,
            'is_complete' => $overallProgress === 100,
        ]);
    }

    /**
     * Calculate deployment progress for a strategy (0-4 steps).
     */
    private function calculateDeploymentProgress($strategy): int
    {
        $progress = 0;

        // Step 1: Collateral generated
        if ($strategy->ad_copies_count > 0 || $strategy->image_collaterals_count > 0) {
            $progress++;
        }

        // Step 2: Deployment started
        if (in_array($strategy->deployment_status, ['deploying', 'deployed', 'verified'], true)) {
            $progress++;
        }

        // Step 3: Live on the platform
        if ($strategy->deployed_at || in_array($strategy->deployment_status, ['deployed', 'verified'], true)) {
            $progress++;
        }

        // Step 4: VerifyDeployment confirmed the objects exist. This used to
        // repeat step 3's condition, so the bar hit 4/4 the moment a deploy
        // finished, verified or not.
        if ($strategy->deployment_status === 'verified') {
            $progress++;
        }

        return $progress;
    }
}
