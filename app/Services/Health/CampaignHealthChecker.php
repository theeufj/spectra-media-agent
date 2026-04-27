<?php

namespace App\Services\Health;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\GoogleAdsPerformanceData;
use App\Models\FacebookAdsPerformanceData;
use App\Services\FacebookAds\CampaignService as FacebookCampaignService;
use App\Services\GoogleAds\CommonServices\GetAdStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CampaignHealthChecker
{
    use HealthCheckTrait;

    private float $performanceDropThreshold;
    private float $performanceSpikeThreshold;
    private int   $creativeFatigueImpressions;
    private float $creativeFatigueCtrDrop;

    public function __construct()
    {
        $cfg = config('optimization.health_check', []);
        $this->performanceDropThreshold   = $cfg['performance_drop_threshold']   ?? 0.30;
        $this->performanceSpikeThreshold  = $cfg['performance_spike_threshold']  ?? 2.0;
        $this->creativeFatigueImpressions = $cfg['creative_fatigue_impressions'] ?? 10000;
        $this->creativeFatigueCtrDrop     = $cfg['creative_fatigue_ctr_drop']    ?? 0.25;
    }

    public function checkAll(Customer $customer): array
    {
        $health = ['status' => 'healthy', 'issues' => [], 'warnings' => [], 'campaigns' => []];

        $activeCampaigns = Campaign::where('customer_id', $customer->id)
            ->withDeployedPlatforms()
            ->get();

        foreach ($activeCampaigns as $campaign) {
            $campaignHealth = $this->checkSingle($campaign);
            $health['campaigns'][$campaign->id] = $campaignHealth;

            foreach ($campaignHealth['issues'] as $issue) {
                $issue['campaign_id']   = $campaign->id;
                $issue['campaign_name'] = $campaign->name;
                $health['issues'][]     = $issue;
            }
            foreach ($campaignHealth['warnings'] as $warning) {
                $warning['campaign_id']   = $campaign->id;
                $warning['campaign_name'] = $campaign->name;
                $health['warnings'][]     = $warning;
            }
        }

        $health['status'] = $this->determineHealthStatus($health['issues'], $health['warnings']);
        return $health;
    }

    public function checkSingle(Campaign $campaign): array
    {
        $health = ['status' => 'healthy', 'issues' => [], 'warnings' => [], 'metrics' => []];

        try {
            $checks = [
                $this->checkDeliveryStatus($campaign),
                $this->checkZeroDelivery($campaign),
                $this->checkBudgetPacing($campaign),
            ];

            foreach ($checks as $check) {
                $health['issues']   = array_merge($health['issues'],   $check['issues']   ?? []);
                $health['warnings'] = array_merge($health['warnings'], $check['warnings'] ?? []);
            }

            $anomalyHealth = $this->detectPerformanceAnomalies($campaign);
            $health['warnings'] = array_merge($health['warnings'], $anomalyHealth['warnings']);
            $health['metrics']['performance'] = $anomalyHealth['metrics'] ?? [];

            $fatigueHealth = $this->checkCreativeFatigue($campaign);
            $health['warnings'] = array_merge($health['warnings'], $fatigueHealth['warnings']);

            $approvalHealth = $this->checkAdApprovalStatus($campaign);
            $health['issues']   = array_merge($health['issues'],   $approvalHealth['issues']);
            $health['warnings'] = array_merge($health['warnings'], $approvalHealth['warnings']);

        } catch (\Exception $e) {
            Log::error("CampaignHealthChecker: Error checking campaign health", [
                'campaign_id' => $campaign->id,
                'error'       => $e->getMessage(),
            ]);
        }

        $health['status'] = $this->determineHealthStatus($health['issues'], $health['warnings']);
        return $health;
    }

    private function checkDeliveryStatus(Campaign $campaign): array
    {
        $health = ['issues' => [], 'warnings' => []];

        if ($campaign->google_ads_campaign_id && $campaign->customer?->google_ads_customer_id) {
            try {
                $customer     = $campaign->customer;
                $customerId   = $customer->cleanGoogleCustomerId();
                $resourceName = $campaign->google_ads_campaign_id;
                if (!str_starts_with($resourceName, 'customers/')) {
                    $resourceName = "customers/{$customerId}/campaigns/{$resourceName}";
                }

                $statusData = (new \App\Services\GoogleAds\CommonServices\GetCampaignStatus($customer))($customerId, $resourceName);

                if ($statusData) {
                    $campaignStatus = match ($statusData['status']) {
                        2 => 'ENABLED', 3 => 'PAUSED', 4 => 'REMOVED', default => 'UNKNOWN',
                    };
                    $primaryStatus = match ($statusData['primary_status']) {
                        2 => 'ELIGIBLE', 3 => 'PAUSED', 4 => 'REMOVED', 5 => 'ENDED',
                        6 => 'PENDING', 7 => 'MISCONFIGURED', 8 => 'LIMITED', default => 'UNKNOWN',
                    };

                    if ($campaignStatus !== 'ENABLED' || in_array($primaryStatus, ['PAUSED', 'REMOVED', 'ENDED', 'MISCONFIGURED'], true)) {
                        $health['issues'][] = [
                            'type'     => 'google_campaign_not_serving',
                            'severity' => 'critical',
                            'message'  => "Google campaign is not serving normally ({$campaignStatus} / {$primaryStatus})",
                            'details'  => 'Check campaign status, policy issues, and billing in Google Ads.',
                        ];
                    } elseif (in_array($primaryStatus, ['PENDING', 'LIMITED', 'UNKNOWN'], true)) {
                        $health['warnings'][] = [
                            'type'     => 'google_campaign_limited',
                            'severity' => 'high',
                            'message'  => "Google campaign requires attention ({$campaignStatus} / {$primaryStatus})",
                            'details'  => 'The campaign is enabled but not yet fully serving normally.',
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('CampaignHealthChecker: Could not check Google campaign delivery status', [
                    'campaign_id' => $campaign->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        if ($campaign->facebook_ads_campaign_id && $campaign->customer?->facebook_ads_account_id) {
            try {
                $fbCampaign = (new FacebookCampaignService($campaign->customer))
                    ->getCampaign($campaign->facebook_ads_campaign_id);

                if ($fbCampaign) {
                    $effectiveStatus = $fbCampaign['effective_status'] ?? 'UNKNOWN';
                    if (in_array($effectiveStatus, ['PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED', 'DISAPPROVED', 'DELETED', 'ARCHIVED'], true)) {
                        $health['issues'][] = [
                            'type'     => 'facebook_campaign_not_serving',
                            'severity' => 'critical',
                            'message'  => "Facebook campaign is not serving normally ({$effectiveStatus})",
                            'details'  => 'Check campaign, ad set, and policy status in Facebook Ads Manager.',
                        ];
                    } elseif (in_array($effectiveStatus, ['WITH_ISSUES', 'PENDING_REVIEW', 'PENDING_BILLING_INFO', 'IN_PROCESS', 'UNKNOWN'], true)) {
                        $health['warnings'][] = [
                            'type'     => 'facebook_campaign_limited',
                            'severity' => 'high',
                            'message'  => "Facebook campaign requires attention ({$effectiveStatus})",
                            'details'  => 'The campaign is not yet fully serving normally.',
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('CampaignHealthChecker: Could not check Facebook campaign delivery status', [
                    'campaign_id' => $campaign->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $health;
    }

    private function checkZeroDelivery(Campaign $campaign): array
    {
        $health = ['issues' => [], 'warnings' => []];

        $deployedAt = $campaign->strategies()
            ->whereIn('deployment_status', ['deployed', 'verified'])
            ->whereNotNull('deployed_at')
            ->min('deployed_at');

        if (!$deployedAt || Carbon::parse($deployedAt)->gt(now()->subHours(6))) {
            return $health;
        }

        if ($campaign->google_ads_campaign_id) {
            $metrics = $this->getGoogleMetricsSummary($campaign);
            if ($metrics && ($metrics['impressions'] ?? 0) === 0) {
                $health['warnings'][] = [
                    'type'     => 'google_zero_delivery',
                    'severity' => 'high',
                    'message'  => 'Google campaign has not recorded impressions since deployment',
                    'details'  => 'Check ad approval, bidding, targeting, and billing before spend is lost to delay.',
                ];
            }
        }

        if ($campaign->facebook_ads_campaign_id) {
            $metrics = $this->getFacebookMetricsSummary($campaign);
            if ($metrics && ($metrics['impressions'] ?? 0) === 0) {
                $health['warnings'][] = [
                    'type'     => 'facebook_zero_delivery',
                    'severity' => 'high',
                    'message'  => 'Facebook campaign has not recorded impressions since deployment',
                    'details'  => 'Check ad set delivery, review state, audience restrictions, and billing.',
                ];
            }
        }

        return $health;
    }

    private function checkBudgetPacing(Campaign $campaign): array
    {
        $health = ['issues' => [], 'warnings' => []];

        if (!$campaign->daily_budget || !$campaign->started_at) {
            return $health;
        }

        $daysRunning   = now()->diffInDays($campaign->started_at);
        $expectedSpend = $campaign->daily_budget * $daysRunning;
        $actualSpend   = $campaign->total_spend ?? 0;

        if ($daysRunning <= 0) {
            return $health;
        }

        $pacingRatio   = $actualSpend / max($expectedSpend, 1);
        $pacingPercent = round($pacingRatio * 100);

        if ($pacingRatio < 0.5) {
            $health['warnings'][] = [
                'type'     => 'underspending',
                'severity' => 'medium',
                'message'  => 'Campaign is significantly underspending',
                'details'  => "Spent \${$actualSpend} of expected \${$expectedSpend} ({$pacingPercent}% of budget)",
            ];
        } elseif ($pacingRatio > 1.2) {
            $health['warnings'][] = [
                'type'     => 'overspending',
                'severity' => 'high',
                'message'  => 'Campaign is overspending budget',
                'details'  => "Spent \${$actualSpend} vs expected \${$expectedSpend}",
            ];
        }

        return $health;
    }

    private function detectPerformanceAnomalies(Campaign $campaign): array
    {
        $health = ['warnings' => [], 'metrics' => []];

        try {
            $recentStart   = now()->subDays(7)->toDateString();
            $recentEnd     = now()->toDateString();
            $previousStart = now()->subDays(14)->toDateString();
            $previousEnd   = now()->subDays(7)->toDateString();

            $model = $campaign->google_ads_campaign_id
                ? GoogleAdsPerformanceData::class
                : ($campaign->facebook_ads_campaign_id ? FacebookAdsPerformanceData::class : null);

            if (!$model) {
                return $health;
            }

            $recent   = $model::where('campaign_id', $campaign->id)->whereBetween('date', [$recentStart, $recentEnd])->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions')->first();
            $previous = $model::where('campaign_id', $campaign->id)->whereBetween('date', [$previousStart, $previousEnd])->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions')->first();

            if (!$recent || !$previous || ($previous->impressions ?? 0) == 0) {
                return $health;
            }

            $anomalyMinImpressions = config('optimization.health_check.anomaly_min_impressions', 1000);
            if (($recent->impressions ?? 0) < $anomalyMinImpressions) {
                return $health;
            }

            $health['metrics'] = [
                'recent_impressions'   => (int) $recent->impressions,
                'previous_impressions' => (int) $previous->impressions,
                'recent_clicks'        => (int) $recent->clicks,
                'previous_clicks'      => (int) $previous->clicks,
            ];

            $recentCtr   = $recent->impressions   > 0 ? $recent->clicks   / $recent->impressions   : 0;
            $previousCtr = $previous->impressions  > 0 ? $previous->clicks / $previous->impressions  : 0;

            if ($previousCtr > 0) {
                $ctrChange = ($recentCtr - $previousCtr) / $previousCtr;
                if ($ctrChange < -$this->performanceDropThreshold) {
                    $dropPercent = round(abs($ctrChange) * 100);
                    $health['warnings'][] = [
                        'type'     => 'ctr_drop',
                        'severity' => 'high',
                        'message'  => "CTR dropped {$dropPercent}% compared to the previous 7 days",
                        'details'  => sprintf('CTR went from %.2f%% to %.2f%%', $previousCtr * 100, $recentCtr * 100),
                    ];
                }
            }

            $recentCpc   = $recent->clicks   > 0 ? $recent->cost   / $recent->clicks   : 0;
            $previousCpc = $previous->clicks  > 0 ? $previous->cost / $previous->clicks  : 0;

            if ($previousCpc > 0) {
                $cpcChange = ($recentCpc - $previousCpc) / $previousCpc;
                if ($cpcChange > ($this->performanceSpikeThreshold - 1)) {
                    $spikePercent = round($cpcChange * 100);
                    $health['warnings'][] = [
                        'type'     => 'cpc_spike',
                        'severity' => 'high',
                        'message'  => "CPC increased {$spikePercent}% compared to the previous 7 days",
                        'details'  => sprintf('CPC went from $%.2f to $%.2f', $previousCpc, $recentCpc),
                    ];
                }
            }

            $recentCvr   = $recent->clicks   > 0 ? $recent->conversions   / $recent->clicks   : 0;
            $previousCvr = $previous->clicks  > 0 ? $previous->conversions / $previous->clicks  : 0;

            if ($previousCvr > 0) {
                $cvrChange = ($recentCvr - $previousCvr) / $previousCvr;
                if ($cvrChange < -$this->performanceDropThreshold) {
                    $dropPercent = round(abs($cvrChange) * 100);
                    $health['warnings'][] = [
                        'type'     => 'conversion_rate_drop',
                        'severity' => 'high',
                        'message'  => "Conversion rate dropped {$dropPercent}% compared to the previous 7 days",
                        'details'  => sprintf('CVR went from %.2f%% to %.2f%%', $previousCvr * 100, $recentCvr * 100),
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::debug("CampaignHealthChecker: Could not detect performance anomalies", [
                'campaign_id' => $campaign->id, 'error' => $e->getMessage(),
            ]);
        }

        return $health;
    }

    private function checkCreativeFatigue(Campaign $campaign): array
    {
        $health = ['warnings' => []];

        try {
            $model = $campaign->google_ads_campaign_id
                ? GoogleAdsPerformanceData::class
                : ($campaign->facebook_ads_campaign_id ? FacebookAdsPerformanceData::class : null);

            if (!$model) {
                return $health;
            }

            $totalImpressions = $model::where('campaign_id', $campaign->id)
                ->where('date', '>=', now()->subDays(30)->toDateString())
                ->sum('impressions');

            if ($totalImpressions < $this->creativeFatigueImpressions) {
                return $health;
            }

            $earlyData  = $model::where('campaign_id', $campaign->id)->whereBetween('date', [now()->subDays(30)->toDateString(), now()->subDays(16)->toDateString()])->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks')->first();
            $recentData = $model::where('campaign_id', $campaign->id)->whereBetween('date', [now()->subDays(15)->toDateString(), now()->toDateString()])->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks')->first();

            if (!$earlyData || !$recentData || ($earlyData->impressions ?? 0) == 0 || ($recentData->impressions ?? 0) == 0) {
                return $health;
            }

            $earlyCtr  = $earlyData->clicks  / $earlyData->impressions;
            $recentCtr = $recentData->clicks / $recentData->impressions;

            if ($earlyCtr > 0) {
                $ctrDrop = ($earlyCtr - $recentCtr) / $earlyCtr;
                if ($ctrDrop >= $this->creativeFatigueCtrDrop) {
                    $dropPercent = round($ctrDrop * 100);
                    $health['warnings'][] = [
                        'type'     => 'creative_fatigue',
                        'severity' => 'medium',
                        'message'  => "Possible creative fatigue: CTR dropped {$dropPercent}% over the last 30 days ({$totalImpressions} impressions)",
                        'details'  => sprintf('CTR went from %.2f%% to %.2f%%', $earlyCtr * 100, $recentCtr * 100),
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::debug("CampaignHealthChecker: Could not check creative fatigue", [
                'campaign_id' => $campaign->id, 'error' => $e->getMessage(),
            ]);
        }

        return $health;
    }

    private function checkAdApprovalStatus(Campaign $campaign): array
    {
        $health = ['issues' => [], 'warnings' => []];

        if ($campaign->google_ads_campaign_id && $campaign->customer) {
            try {
                $customer   = $campaign->customer;
                $customerId = $customer->google_ads_customer_id;
                $resource   = $campaign->google_ads_campaign_id;

                if (!str_starts_with($resource, 'customers/')) {
                    $resource = "customers/{$customerId}/campaigns/{$resource}";
                }

                $ads      = (new GetAdStatus($customer, true))($customerId, $resource);
                $limited  = 0;

                foreach ($ads as $ad) {
                    if (($ad['approval_status'] ?? 0) === 4) {
                        $topics = array_map(fn($t) => $t['topic'] ?? 'unknown', $ad['policy_topics'] ?? []);
                        $health['issues'][] = [
                            'type'     => 'google_ad_disapproved',
                            'severity' => 'high',
                            'message'  => 'A Google ad was disapproved' . (!empty($topics) ? ' for: ' . implode(', ', $topics) : '') . '. Our team is working to resolve this.',
                            'details'  => 'Policy topics: ' . implode(', ', $topics),
                        ];
                    } elseif (($ad['approval_status'] ?? 0) === 3) {
                        $limited++;
                    }
                }

                if ($limited > 0) {
                    $health['warnings'][] = [
                        'type'     => 'google_ads_limited',
                        'severity' => 'medium',
                        'message'  => "{$limited} Google ad(s) have limited approval",
                    ];
                }
            } catch (\Exception $e) {
                Log::warning("CampaignHealthChecker: Could not check Google ad status", [
                    'campaign_id' => $campaign->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        if ($campaign->facebook_ads_campaign_id && $campaign->customer) {
            try {
                $customer     = $campaign->customer;
                $adSetService = new \App\Services\FacebookAds\AdSetService($customer);
                $adService    = new \App\Services\FacebookAds\AdService($customer);
                $adSets       = $adSetService->listAdSets($campaign->facebook_ads_campaign_id) ?? [];

                foreach ($adSets as $adSet) {
                    foreach (($adService->listAds($adSet['id']) ?? []) as $ad) {
                        if (($ad['status'] ?? '') === 'DISAPPROVED') {
                            $health['issues'][] = [
                                'type'     => 'facebook_ad_disapproved',
                                'severity' => 'high',
                                'message'  => "A Facebook ad was disapproved: \"{$ad['name']}\". Our team is reviewing this.",
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning("CampaignHealthChecker: Could not check Facebook ad status", [
                    'campaign_id' => $campaign->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $health;
    }

    private function getGoogleMetricsSummary(Campaign $campaign): ?array
    {
        try {
            $customer = $campaign->customer;
            if (!$customer || !$campaign->google_ads_campaign_id) {
                return null;
            }

            preg_match('/campaigns\/(\d+)$/', $campaign->google_ads_campaign_id, $matches);
            $campaignId = $matches[1] ?? $campaign->google_ads_campaign_id;

            $service = new class($customer) extends \App\Services\GoogleAds\BaseGoogleAdsService {
                public function getMetrics(string $customerId, string $campaignId): ?array
                {
                    $this->ensureClient();
                    $query    = "SELECT metrics.impressions, metrics.clicks, metrics.cost_micros FROM campaign WHERE campaign.id = {$campaignId} AND segments.date BETWEEN '"
                        . now()->subDays(1)->toDateString() . "' AND '" . now()->toDateString() . "'";
                    $response = $this->searchQuery($customerId, $query);
                    $metrics  = ['impressions' => 0, 'clicks' => 0, 'cost' => 0.0];

                    foreach ($response->getIterator() as $row) {
                        $m = $row->getMetrics();
                        $metrics['impressions'] += $m->getImpressions();
                        $metrics['clicks']      += $m->getClicks();
                        $metrics['cost']        += $m->getCostMicros() / 1_000_000;
                    }

                    return $metrics;
                }
            };

            return $service->getMetrics($customer->cleanGoogleCustomerId(), (string) $campaignId);
        } catch (\Exception $e) {
            Log::debug('CampaignHealthChecker: Could not fetch Google campaign metrics', [
                'campaign_id' => $campaign->id, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function getFacebookMetricsSummary(Campaign $campaign): ?array
    {
        try {
            $customer = $campaign->customer;
            if (!$customer || !$campaign->facebook_ads_campaign_id) {
                return null;
            }

            $insightService = new \App\Services\FacebookAds\InsightService($customer);
            $adSets         = (new \App\Services\FacebookAds\AdSetService($customer))->listAdSets($campaign->facebook_ads_campaign_id) ?? [];
            $metrics        = ['impressions' => 0, 'clicks' => 0, 'cost' => 0.0];

            foreach ($adSets as $adSet) {
                if (empty($adSet['id'])) continue;
                $insights = $insightService->getAdSetInsights($adSet['id'], now()->subDays(1)->toDateString(), now()->toDateString()) ?? [];
                foreach ($insights as $insight) {
                    $metrics['impressions'] += (int)   ($insight['impressions'] ?? 0);
                    $metrics['clicks']      += (int)   ($insight['clicks']      ?? 0);
                    $metrics['cost']        += (float) ($insight['spend']       ?? 0);
                }
            }

            return $metrics;
        } catch (\Exception $e) {
            Log::debug('CampaignHealthChecker: Could not fetch Facebook campaign metrics', [
                'campaign_id' => $campaign->id, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
