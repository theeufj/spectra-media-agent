<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCampaignRequest;
use App\Jobs\GenerateStrategy;
use App\Jobs\GenerateCampaignCollateral;
use App\Jobs\GenerateStrategyCollateral;
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
        return redirect()->route('campaigns.wizard');
    }

    /**
     * wizard is the handler for showing the campaign creation wizard.
     */
    public function wizard(Request $request)
    {
        $customer = $request->user()->customers()->findOrFail(session('active_customer_id'));

        // Load available pages for the customer
        $pages = $customer->pages()
            ->orderBy('created_at', 'desc')
            ->get(['id', 'url', 'title', 'meta_description'])
            ->toArray();

        // If no customer_pages exist, backfill from knowledge_bases
        if (empty($pages)) {
            $userIds = $customer->users()->pluck('users.id');
            $kbEntries = \App\Models\KnowledgeBase::whereIn('user_id', $userIds)
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
        $allowedPlatforms = $request->user()->allowedPlatforms();
        $configuredPlatforms = $customer->configuredPlatforms();

        $selectablePlatforms = array_values(array_intersect(
            array_map('strtolower', $enabledPlatforms),
            $allowedPlatforms,
            $configuredPlatforms
        ));

        return Inertia::render('Campaigns/CreateWizard', [
            'pages' => $pages,
            'brandGuideline' => $brandGuideline,
            'allowedPlatforms' => $allowedPlatforms,
            'selectablePlatforms' => $selectablePlatforms,
            'configuredPlatforms' => $configuredPlatforms,
        ]);
    }

    /**
     * deploymentStatus is the handler for showing the deployment status page.
     */
    public function deploymentStatus(Request $request, Campaign $campaign)
    {
        $customer = $request->user()->customers()->findOrFail(session('active_customer_id'));
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
        ]);
    }

    /**
     * store is the handler for creating a new campaign.
     */
    public function store(StoreCampaignRequest $request)
    {
        $customer = $request->user()->customers()->findOrFail(session('active_customer_id'));

        // Enforce campaign limit for Free plan (max 1)
        $user = $request->user();
        $plan = $user->resolveCurrentPlan();
        if (($plan?->slug ?? 'free') === 'free') {
            $existingCount = $customer->campaigns()->count();
            if ($existingCount >= 1) {
                return redirect()->back()->with('flash', [
                    'type' => 'error',
                    'message' => 'Free plan is limited to 1 campaign. Please upgrade to create more campaigns.',
                ]);
            }
        }

        $validated = $request->validated();

        // Extract keywords before creating campaign (not a campaign column)
        $keywords = $validated['keywords'] ?? [];
        unset($validated['keywords']);

        // Calculate daily_budget if not provided
        if (empty($validated['daily_budget']) && !empty($validated['total_budget'])) {
            $startDate = \Carbon\Carbon::parse($validated['start_date']);
            $endDate = \Carbon\Carbon::parse($validated['end_date']);
            $days = max(1, $startDate->diffInDays($endDate) + 1); // +1 to include both start and end days
            $validated['daily_budget'] = round($validated['total_budget'] / $days, 2);
        }

        $campaign = $customer->campaigns()->create($validated);

        if ($request->has('selected_pages')) {
            $campaign->pages()->attach($request->input('selected_pages'));
        }

        // Store user-selected keywords
        if (!empty($keywords)) {
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
     * regenerateStrategies deletes existing strategies and re-dispatches the generation job.
     */
    public function regenerateStrategies(Request $request, Campaign $campaign)
    {
        $customer = $request->user()->customers()->findOrFail(session('active_customer_id'));
        if ($campaign->customer_id !== $customer->id) {
            abort(403);
        }

        $force = $request->boolean('force', false);

        if ($campaign->strategies()->whereNotNull('signed_off_at')->exists()) {
            if (!$force) {
                return back()->with('error', 'Some strategies are already signed off. Use force regeneration to revert sign-offs and start over.');
            }
            // Force regeneration: delete all collateral and revert sign-offs
            $campaign->strategies->each(function ($strategy) {
                $strategy->adCopies()->delete();
                $strategy->imageCollaterals()->delete();
                $strategy->videoCollaterals()->delete();
            });
        }

        // Delete existing strategies
        $campaign->strategies()->delete();

        // Reset generation state and re-dispatch
        $campaign->update([
            'strategy_generation_started_at' => now(),
            'strategy_generation_error' => null,
        ]);

        GenerateStrategy::dispatch($campaign);

        return back()->with('success', 'Regenerating strategies...');
    }

    /**
     * aiAssist provides AI-powered assistance for campaign creation through a chat interface.
     * Uses Google Gemini API directly for simplicity.
     */
    public function aiAssist(Request $request)
    {
        $messages = $request->input('messages', []);
        $currentData = $request->input('current_data', []);

        // Get the customer's brand guidelines
        $customer = $request->user()->customers()->find(session('active_customer_id'));
        $brandGuidelines = $customer?->brandGuideline;

        // Build the system instruction using the prompt class
        $systemInstruction = \App\Prompts\CampaignWizardPrompt::build($brandGuidelines);

        // Build conversation history for Gemini API
        $contents = [];
        foreach ($messages as $msg) {
            $contents[] = [
                'role' => $msg['role'] === 'user' ? 'user' : 'model',
                'parts' => [['text' => $msg['content']]]
            ];
        }

        try {
            $apiKey = config('services.google.gemini_api_key');

            if (!$apiKey) {
                throw new \Exception('Gemini API key not configured');
            }

            $response = \Illuminate\Support\Facades\Http::post(
                "https://generativelanguage.googleapis.com/v1beta/models/" . config('ai.models.default') . ":generateContent?key={$apiKey}",
                [
                    'contents' => $contents,
                    'systemInstruction' => [
                        'parts' => [['text' => $systemInstruction]]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 2048,
                    ],
                ]
            );

            if (!$response->successful()) {
                \Log::error('Gemini API Error', ['status' => $response->status(), 'body' => $response->body()]);
                throw new \Exception('Gemini API request failed');
            }

            $responseData = $response->json();
            $aiMessage = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? 'I apologize, but I encountered an issue. Please try again.';

            // Extract campaign data if present
            $campaignData = null;
            if (preg_match('/```campaign_data\s*\n(.*?)\n```/s', $aiMessage, $matches)) {
                try {
                    $campaignData = json_decode($matches[1], true);
                    // Clean the message by removing the JSON block
                    $aiMessage = trim(preg_replace('/```campaign_data\s*\n.*?\n```/s', '', $aiMessage));

                    // If no voice was extracted but we have brand guidelines, use default
                    if (empty($campaignData['voice']) && $brandGuidelines) {
                        $campaignData['voice'] = \App\Prompts\CampaignWizardPrompt::getDefaultVoice($brandGuidelines);
                    }
                } catch (\Exception $e) {
                    // JSON parsing failed, continue without campaign data
                }
            }

            return response()->json([
                'message' => $aiMessage,
                'campaign_data' => $campaignData,
            ]);

        } catch (\Exception $e) {
            \Log::error('AI Assist Error: ' . $e->getMessage());

            return response()->json([
                'message' => 'I apologize, but I encountered an error. Please try again or use the template option instead.',
                'campaign_data' => null,
            ], 500);
        }
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
     * Allows access for: campaign owner OR admin users
     */
    public function performance(Request $request, Campaign $campaign)
    {
        $user = $request->user();

        // Check if user is admin OR owns this campaign
        $isAdmin = $user->hasRole('admin');
        $isOwner = $user->customers()->where('customers.id', $campaign->customer_id)->exists();

        if (!$isAdmin && !$isOwner) {
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

        if (!$connection || !$campaign->google_ads_campaign_id) {
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
            $service = new \App\Services\GoogleAds\CommonServices\GetCampaignPerformance(
                $connection->platform_user_id,
                $connection->access_token,
                $connection->refresh_token
            );

            $resourceName = $campaign->googleAdsResourceName();
            $metrics = $service($connection->platform_user_id, $resourceName, 'LAST_30_DAYS');

            // Get daily data from stored records
            $dailyData = $this->getStoredDailyData($campaign, $startDate, $endDate);

            if (!$metrics) {
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

        } catch (\Exception $e) {
            \Log::error("Failed to fetch performance for campaign {$campaign->id}: " . $e->getMessage());

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

    /**
     * API endpoint to get deployment status for a campaign (for polling).
     */
    public function apiDeploymentStatus(Request $request, Campaign $campaign)
    {
        // Check if user has access to this campaign
        $user = $request->user();
        $hasAccess = $user->customers()->where('customers.id', $campaign->customer_id)->exists();

        if (!$hasAccess) {
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
                'status' => $campaign->status ?? 'pending',
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
        if ($strategy->deployment_status === 'deploying' || $strategy->deployment_status === 'deployed') {
            $progress++;
        }

        // Step 3: Platform configured
        if ($strategy->deployment_status === 'deployed' || $strategy->deployed_at) {
            $progress++;
        }

        // Step 4: Verification complete
        if ($strategy->deployed_at && $strategy->deployment_status === 'deployed') {
            $progress++;
        }

        return $progress;
    }
}
