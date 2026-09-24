<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignHourlyPerformance;
use App\Models\LinkedInAdsPerformanceData;
use App\Models\MicrosoftAdsPerformanceData;
use App\Services\Agents\BudgetIntelligenceAgent;
use App\Services\Agents\CampaignAlertService;
use App\Services\FacebookAds\InsightService as FacebookInsightService;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * HourlyBudgetOptimization
 *
 * Runs every hour to apply budget multipliers using learned per-account performance curves.
 * Also snapshots hourly performance data for building those curves over time.
 *
 * Lighter-weight than AutomatedCampaignMaintenance (which runs daily with healing + mining).
 */
class HourlyBudgetOptimization implements ShouldQueue
{
    use \App\Jobs\Concerns\RecordsAgentRun, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes max

    public function handle(BudgetIntelligenceAgent $budgetAgent): void
    {
        // Prevent duplicate runs if the scheduler dispatches a second instance before the first finishes
        $lock = Cache::lock('hourly-budget-optimization', 3300); // 55 min — releases before next hour
        if (! $lock->get()) {
            Log::info('HourlyBudgetOptimization: Skipping — another instance is already running');

            return;
        }

        try {
            $this->run($budgetAgent);
        } finally {
            $lock->release();
        }
    }

    private function run(BudgetIntelligenceAgent $budgetAgent): void
    {
        Log::info('HourlyBudgetOptimization: Starting hourly run');
        $runStart = $this->startRun();

        $alertService = new CampaignAlertService;

        $campaigns = Campaign::with('customer')
            ->serving()
            ->where(fn ($q) => $q->whereNotNull('google_ads_campaign_id')
                ->orWhereNotNull('facebook_ads_campaign_id')
                ->orWhereNotNull('microsoft_ads_campaign_id')
                ->orWhereNotNull('linkedin_campaign_id'))
            ->get();

        $summary = [
            'campaigns_processed' => 0,
            'budget_adjustments' => 0,
            'snapshots_recorded' => 0,
            'alerts_fired' => 0,
            'errors' => 0,
        ];

        foreach ($campaigns as $campaign) {
            try {
                // 1. Snapshot current hourly performance for learning
                $this->recordHourlySnapshot($campaign);
                $summary['snapshots_recorded']++;

                // 2. Apply budget multiplier
                $results = $budgetAgent->optimize($campaign);
                $summary['errors'] += count($results['errors'] ?? []);
                $adjustments = array_filter(
                    $results['adjustments'] ?? [],
                    fn ($a) => $a['type'] === 'budget_updated'
                );
                $summary['budget_adjustments'] += count($adjustments);

                // 3. Check for critical alerts (budget exhaustion, conversion drops, spend anomalies)
                $alerts = $alertService->checkAlerts($campaign);
                $summary['alerts_fired'] += count($alerts);

                $summary['campaigns_processed']++;
            } catch (\Throwable $e) {
                // Surface in the admin exception dashboard; the batch continues.
                report($e);
                $summary['errors']++;
                Log::error('HourlyBudgetOptimization: Failed', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('HourlyBudgetOptimization: Completed', $summary);

        $this->finishRun($runStart, actions: $summary['budget_adjustments'], errors: $summary['errors'], scope: $campaigns->count().' campaigns', details: $summary);
    }

    /**
     * Record a performance snapshot for the current hour.
     * This data feeds the learned multiplier model.
     *
     * Every platform here reports day-to-date totals, so the hour's own figures
     * are the difference against what has already been recorded for today. The
     * row used to store the running total: averaging an accumulating series and
     * multiplying by 24 (AdaptiveThresholds) overstated daily volume by roughly
     * an order of magnitude, and the hour-of-day ROAS multipliers were computed
     * from cumulative ratios that flatten out as the day goes on.
     */
    protected function recordHourlySnapshot(Campaign $campaign): void
    {
        $customer = $campaign->customer;
        if (! $customer) {
            return;
        }

        $now = now()->utc();
        $hour = (int) $now->format('H');
        $date = $now->toDateString();
        $dayOfWeek = (int) $now->format('w'); // 0=Sunday

        // Today's cumulative performance from the platform
        $metrics = $this->getHourlyMetrics($campaign, $customer);
        if (! $metrics) {
            return;
        }

        // What today's earlier hours already account for. Summing the stored
        // deltas (rather than reading the previous hour alone) keeps the maths
        // right when a run is skipped: the next snapshot then covers both hours.
        $recorded = CampaignHourlyPerformance::where('campaign_id', $campaign->id)
            ->where('date', $date)
            ->where('platform', $metrics['platform'])
            ->where('hour', '<', $hour)
            ->selectRaw('
                COALESCE(SUM(impressions), 0) as impressions,
                COALESCE(SUM(clicks), 0) as clicks,
                COALESCE(SUM(conversions), 0) as conversions,
                COALESCE(SUM(spend), 0) as spend,
                COALESCE(SUM(conversion_value), 0) as conversion_value
            ')
            ->first();

        // A platform restating a figure downwards would otherwise write a
        // negative hour and poison every average built on these rows.
        $impressions = max(0, (int) ($metrics['impressions'] ?? 0) - (int) ($recorded->impressions ?? 0));
        $clicks = max(0, (int) ($metrics['clicks'] ?? 0) - (int) ($recorded->clicks ?? 0));
        $conversions = max(0, (float) ($metrics['conversions'] ?? 0) - (float) ($recorded->conversions ?? 0));
        $spend = max(0, (float) ($metrics['spend'] ?? 0) - (float) ($recorded->spend ?? 0));
        $conversionValue = max(0, (float) ($metrics['conversion_value'] ?? 0) - (float) ($recorded->conversion_value ?? 0));

        CampaignHourlyPerformance::updateOrCreate(
            [
                'campaign_id' => $campaign->id,
                'date' => $date,
                'hour' => $hour,
                'platform' => $metrics['platform'],
            ],
            [
                'customer_id' => $customer->id,
                'day_of_week' => $dayOfWeek,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'conversions' => $conversions,
                'spend' => $spend,
                'conversion_value' => $conversionValue,
                'ctr' => $impressions > 0 ? $clicks / $impressions : 0,
                'roas' => $spend > 0 ? $conversionValue / $spend : 0,
            ]
        );
    }

    /**
     * Fetch today's cumulative metrics from the platform for snapshot delta.
     */
    protected function getHourlyMetrics(Campaign $campaign, $customer): ?array
    {
        $today = now()->toDateString();

        // Google Ads
        if ($campaign->google_ads_campaign_id && $customer->google_ads_customer_id) {
            try {
                $customerId = $customer->google_ads_customer_id;
                $resourceName = $campaign->googleAdsResourceName();
                $getPerformance = new GetCampaignPerformance($customer);
                $metrics = ($getPerformance)($customerId, $resourceName, 'TODAY');

                if ($metrics) {
                    return [
                        'platform' => 'google_ads',
                        'impressions' => $metrics['impressions'] ?? 0,
                        'clicks' => $metrics['clicks'] ?? 0,
                        'conversions' => $metrics['conversions'] ?? 0,
                        'spend' => ($metrics['cost_micros'] ?? 0) / 1000000,
                        'conversion_value' => $metrics['conversion_value'] ?? 0,
                    ];
                }
            } catch (\Throwable $e) {
                report($e);
                // Fall through to return null
            }
        }

        // Microsoft Ads — SOAP API has no live intra-day endpoint; use today's stored
        // performance data as the best available proxy. Today only: the caller reads
        // these as day-to-date totals and subtracts what today's earlier hours already
        // recorded, so yesterday's full day would land as one enormous hour and then
        // sink every real reading behind it.
        if ($campaign->microsoft_ads_campaign_id && $customer->microsoft_ads_customer_id) {
            $msRow = MicrosoftAdsPerformanceData::where('campaign_id', $campaign->id)
                ->where('date', $today)
                ->orderByDesc('updated_at')
                ->first();

            if ($msRow) {
                return [
                    'platform' => 'microsoft_ads',
                    'impressions' => (int) $msRow->impressions,
                    'clicks' => (int) $msRow->clicks,
                    'conversions' => (float) $msRow->conversions,
                    'spend' => (float) $msRow->cost,
                    'conversion_value' => (float) $msRow->conversion_value,
                ];
            }
        }

        // LinkedIn Ads — use stored performance data (Marketing API is batch/async like
        // Microsoft), today only, for the same reason.
        if ($campaign->linkedin_campaign_id && $customer->linkedin_ads_account_id) {
            $liRow = LinkedInAdsPerformanceData::where('campaign_id', $campaign->id)
                ->where('date', $today)
                ->orderByDesc('updated_at')
                ->first();

            if ($liRow) {
                return [
                    'platform' => 'linkedin_ads',
                    'impressions' => (int) $liRow->impressions,
                    'clicks' => (int) $liRow->clicks,
                    'conversions' => (float) $liRow->conversions,
                    'spend' => (float) $liRow->cost,
                    'conversion_value' => (float) $liRow->conversion_value,
                ];
            }
        }

        // Facebook Ads
        if ($campaign->facebook_ads_campaign_id && $customer->facebook_ads_account_id) {
            try {
                $insightService = new FacebookInsightService($customer);
                $insights = $insightService->getCampaignInsights(
                    $campaign->facebook_ads_campaign_id,
                    $today,
                    $today
                );

                if (! empty($insights)) {
                    $day = $insights[0];
                    $conversionValue = 0;
                    foreach ($day['action_values'] ?? [] as $av) {
                        if (($av['action_type'] ?? '') === 'purchase') {
                            $conversionValue += (float) ($av['value'] ?? 0);
                        }
                    }

                    return [
                        'platform' => 'facebook_ads',
                        'impressions' => (int) ($day['impressions'] ?? 0),
                        'clicks' => (int) ($day['clicks'] ?? 0),
                        'conversions' => $insightService->parseAction($day['actions'] ?? null, 'purchase'),
                        'spend' => (float) ($day['spend'] ?? 0),
                        'conversion_value' => $conversionValue,
                    ];
                }
            } catch (\Throwable $e) {
                report($e);
                // Fall through to return null
            }
        }

        return null;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('HourlyBudgetOptimization failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
        $this->recordRunFailure($exception);
    }
}
