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
        return Inertia::render('Campaigns/Create');
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
        
        return Inertia::render('Campaigns/CreateWizard', [
            'pages' => $pages,
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
        
        $validated = $request->validated();
        
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
                "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}",
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

        // Get Google Ads connection
        $connection = $campaign->customer->users()->first()?->connections()
            ->where('platform', 'google_ads')
            ->first();

        if (!$connection || !$campaign->google_ads_campaign_id) {
            return response()->json([
                'summary' => [
                    'impressions' => 0,
                    'clicks' => 0,
                    'cost' => 0,
                    'conversions' => 0,
                    'ctr' => 0,
                    'cpc' => 0,
                    'cpa' => 0,
                ],
                'daily_data' => [],
                'message' => 'No Google Ads connection or campaign not deployed',
            ]);
        }

        try {
            $service = new \App\Services\GoogleAds\CommonServices\GetCampaignPerformance(
                $connection->platform_user_id,
                $connection->access_token,
                $connection->refresh_token
            );

            $resourceName = "customers/{$connection->platform_user_id}/campaigns/{$campaign->google_ads_campaign_id}";
            $metrics = $service($connection->platform_user_id, $resourceName, 'LAST_30_DAYS');

            if (!$metrics) {
                return response()->json([
                    'summary' => [
                        'impressions' => 0,
                        'clicks' => 0,
                        'cost' => 0,
                        'conversions' => 0,
                        'ctr' => 0,
                        'cpc' => 0,
                        'cpa' => 0,
                    ],
                    'daily_data' => [],
                    'message' => 'No performance data available yet',
                ]);
            }

            return response()->json([
                'summary' => [
                    'impressions' => $metrics['impressions'],
                    'clicks' => $metrics['clicks'],
                    'cost' => $metrics['cost_micros'] / 1000000,
                    'conversions' => $metrics['conversions'],
                    'ctr' => round($metrics['ctr'] * 100, 2),
                    'cpc' => $metrics['average_cpc'] / 1000000,
                    'cpa' => $metrics['cost_per_conversion'] / 1000000,
                ],
                'daily_data' => [], // Could be expanded to include daily breakdown
            ]);

        } catch (\Exception $e) {
            \Log::error("Failed to fetch performance for campaign {$campaign->id}: " . $e->getMessage());
            return response()->json([
                'summary' => [
                    'impressions' => 0,
                    'clicks' => 0,
                    'cost' => 0,
                    'conversions' => 0,
                    'ctr' => 0,
                    'cpc' => 0,
                    'cpa' => 0,
                ],
                'daily_data' => [],
                'error' => 'Failed to fetch performance data',
            ]);
        }
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
