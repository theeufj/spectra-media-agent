<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignHourlyPerformance;
use App\Models\MicrosoftAdsPerformanceData;
use App\Models\LinkedInAdsPerformanceData;
use App\Services\Agents\BudgetIntelligenceAgent;
use App\Services\Agents\CampaignAlertService;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\FacebookAds\InsightService as FacebookInsightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes max

    public function handle(BudgetIntelligenceAgent $budgetAgent): void
    {
        Log::info('HourlyBudgetOptimization: Starting hourly run');

        $alertService = new CampaignAlertService();

        $campaigns = Campaign::with('customer')
            ->where('primary_status', 'ELIGIBLE')
            ->where(fn($q) => $q->whereNotNull('google_ads_campaign_id')
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
                $adjustments = array_filter(
                    $results['adjustments'] ?? [],
                    fn($a) => $a['type'] === 'budget_updated'
                );
                $summary['budget_adjustments'] += count($adjustments);

                // 3. Check for critical alerts (budget exhaustion, conversion drops, spend anomalies)
                $alerts = $alertService->checkAlerts($campaign);
                $summary['alerts_fired'] += count($alerts);

                $summary['campaigns_processed']++;
            } catch (\Exception $e) {
                $summary['errors']++;
                Log::error('HourlyBudgetOptimization: Failed', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('HourlyBudgetOptimization: Completed', $summary);
    }

    /**
     * Record a performance snapshot for the current hour.
     * This data feeds the learned multiplier model.
     */
    protected function recordHourlySnapshot(Campaign $campaign): void
    {
        $customer = $campaign->customer;
        if (!$customer) return;

        $now = now();
        $hour = (int) $now->format('H');
        $date = $now->toDateString();
        $dayOfWeek = (int) $now->format('w'); // 0=Sunday

        // Get current hour's performance from the platform
        $metrics = $this->getHourlyMetrics($campaign, $customer);
        if (!$metrics) return;

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
                'impressions' => $metrics['impressions'] ?? 0,
                'clicks' => $metrics['clicks'] ?? 0,
                'conversions' => $metrics['conversions'] ?? 0,
                'spend' => $metrics['spend'] ?? 0,
                'conversion_value' => $metrics['conversion_value'] ?? 0,
                'ctr' => ($metrics['impressions'] ?? 0) > 0
                    ? ($metrics['clicks'] ?? 0) / $metrics['impressions']
                    : 0,
                'roas' => ($metrics['spend'] ?? 0) > 0
                    ? ($metrics['conversion_value'] ?? 0) / $metrics['spend']
                    : 0,
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
                $getPerformance = new GetCampaignPerformance($customer, true);
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
            } catch (\Exception $e) {
                // Fall through to return null
            }
        }

        // Microsoft Ads — SOAP API has no live intra-day endpoint; use today's stored
        // performance data as the best available proxy. Falls back to yesterday if today
        // has no records yet (e.g., early morning before the first fetch).
        if ($campaign->microsoft_ads_campaign_id && $customer->microsoft_ads_customer_id) {
            $msRow = MicrosoftAdsPerformanceData::where('campaign_id', $campaign->id)
                ->where('date', $today)
                ->orderByDesc('updated_at')
                ->first()
                ?? MicrosoftAdsPerformanceData::where('campaign_id', $campaign->id)
                    ->where('date', now()->subDay()->toDateString())
                    ->orderByDesc('updated_at')
                    ->first();

            if ($msRow) {
                return [
                    'platform'         => 'microsoft_ads',
                    'impressions'      => (int) $msRow->impressions,
                    'clicks'           => (int) $msRow->clicks,
                    'conversions'      => (float) $msRow->conversions,
                    'spend'            => (float) $msRow->cost,
                    'conversion_value' => (float) $msRow->conversion_value,
                ];
            }
        }

        // LinkedIn Ads — use stored performance data (Marketing API is batch/async like Microsoft)
        if ($campaign->linkedin_campaign_id && $customer->linkedin_ads_account_id) {
            $liRow = LinkedInAdsPerformanceData::where('campaign_id', $campaign->id)
                ->where('date', $today)
                ->orderByDesc('updated_at')
                ->first()
                ?? LinkedInAdsPerformanceData::where('campaign_id', $campaign->id)
                    ->where('date', now()->subDay()->toDateString())
                    ->orderByDesc('updated_at')
                    ->first();

            if ($liRow) {
                return [
                    'platform'         => 'linkedin_ads',
                    'impressions'      => (int) $liRow->impressions,
                    'clicks'           => (int) $liRow->clicks,
                    'conversions'      => (float) $liRow->conversions,
                    'spend'            => (float) $liRow->cost,
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

                if (!empty($insights)) {
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
            } catch (\Exception $e) {
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
        Log::error('HourlyBudgetOptimization failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
