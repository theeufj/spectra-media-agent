<?php

namespace App\Services\Agents;

use App\Models\AuditSession;
use App\Models\Customer;
use App\Services\GeminiService;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GoogleAds\CommonServices\GetAdStatus;
use App\Services\GoogleAds\ConversionTrackingService;
use App\Services\FacebookAds\AdAccountService;
use App\Services\FacebookAds\AdService;
use App\Services\FacebookAds\AdSetService;
use App\Services\FacebookAds\CampaignService;
use App\Services\FacebookAds\InsightService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AccountAuditAgent
{
    protected GeminiService $gemini;

    protected array $findings = [];
    protected int $score = 100;

    // Severity weights for score calculation
    protected array $severityWeights = [
        'critical' => 15,
        'high' => 10,
        'medium' => 5,
        'low' => 2,
    ];

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    /**
     * Run a comprehensive audit on an external ad account.
     */
    public function audit(AuditSession $auditSession): array
    {
        Log::info("AccountAuditAgent: Starting audit for session {$auditSession->id}", [
            'platform' => $auditSession->platform,
        ]);

        $this->findings = [];
        $this->score = 100;

        $results = match ($auditSession->platform) {
            'google' => $this->auditGoogleAds($auditSession),
            'facebook' => $this->auditFacebookAds($auditSession),
            default => ['error' => 'Unsupported platform'],
        };

        // Calculate final score
        $this->score = max(0, $this->score);

        // Generate AI recommendations from findings
        $recommendations = $this->generateRecommendations($this->findings, $auditSession->platform);

        $results = [
            'platform' => $auditSession->platform,
            'score' => $this->score,
            'findings' => $this->findings,
            'recommendations' => $recommendations,
            'summary' => $this->generateSummary(),
            'audited_at' => now()->toIso8601String(),
        ];

        Log::info("AccountAuditAgent: Completed audit for session {$auditSession->id}", [
            'score' => $this->score,
            'findings_count' => count($this->findings),
        ]);

        return $results;
    }

    /**
     * Audit a Google Ads account.
     */
    protected function auditGoogleAds(AuditSession $auditSession): array
    {
        // Build a temporary Customer object with the audit token
        $tempCustomer = new Customer();
        $tempCustomer->google_ads_refresh_token = $auditSession->refresh_token_encrypted;
        $tempCustomer->google_ads_customer_id = $auditSession->google_ads_customer_id;

        $customerId = $auditSession->google_ads_customer_id;
        $metrics = [];

        try {
            // 1. Check campaign structure and performance
            $metrics['campaigns'] = $this->auditGoogleCampaigns($tempCustomer, $customerId);

            // 2. Check ad approval status and policy violations
            $metrics['ads'] = $this->auditGoogleAdStatus($tempCustomer, $customerId);

            // 3. Check conversion tracking
            $metrics['conversions'] = $this->auditGoogleConversionTracking($tempCustomer, $customerId);

            // 4. Check ad group structure
            $metrics['ad_groups'] = $this->auditGoogleAdGroupStructure($tempCustomer, $customerId);

            // 5. Check keyword health
            $metrics['keywords'] = $this->auditGoogleKeywords($tempCustomer, $customerId);

            // 6. Check ad extensions
            $metrics['extensions'] = $this->auditGoogleExtensions($tempCustomer, $customerId);

        } catch (\Exception $e) {
            Log::error("AccountAuditAgent: Google Ads audit error", [
                'session_id' => $auditSession->id,
                'error' => $e->getMessage(),
            ]);
            $this->addFinding('error', 'critical', 'Audit Error', 'Could not fully audit the account: ' . $e->getMessage());
        }

        return $metrics;
    }

    /**
     * Audit Google campaign performance for wasted spend.
     */
    protected function auditGoogleCampaigns(Customer $customer, string $customerId): array
    {
        $service = new class($customer) extends BaseGoogleAdsService {
            public function getCampaignOverview(string $customerId): array
            {
                $this->ensureClient();

                $query = "SELECT " .
                    "campaign.id, " .
                    "campaign.name, " .
                    "campaign.status, " .
                    "campaign.campaign_budget, " .
                    "campaign.advertising_channel_type, " .
                    "metrics.impressions, " .
                    "metrics.clicks, " .
                    "metrics.cost_micros, " .
                    "metrics.conversions, " .
                    "metrics.ctr, " .
                    "metrics.average_cpc " .
                    "FROM campaign " .
                    "WHERE segments.date DURING LAST_30_DAYS " .
                    "AND campaign.status != 'REMOVED'";

                $results = [];
                $response = $this->searchQuery($customerId, $query);
                foreach ($response->getIterator() as $row) {
                    $campaign = $row->getCampaign();
                    $metrics = $row->getMetrics();
                    $results[] = [
                        'id' => $campaign->getId(),
                        'name' => $campaign->getName(),
                        'status' => $campaign->getStatus(),
                        'channel_type' => $campaign->getAdvertisingChannelType(),
                        'impressions' => $metrics->getImpressions(),
                        'clicks' => $metrics->getClicks(),
                        'cost' => $metrics->getCostMicros() / 1_000_000,
                        'conversions' => $metrics->getConversions(),
                        'ctr' => $metrics->getCtr(),
                        'avg_cpc' => $metrics->getAverageCpc() / 1_000_000,
                    ];
                }
                return $results;
            }
        };

        $campaigns = $service->getCampaignOverview($customerId);
        $totalWastedSpend = 0;
        $activeCampaigns = 0;

        foreach ($campaigns as $campaign) {
            if ($campaign['status'] == 2) { // ENABLED
                $activeCampaigns++;
            }

            // Wasted spend: campaigns with spend but zero conversions
            if ($campaign['cost'] > 10 && $campaign['conversions'] == 0) {
                $totalWastedSpend += $campaign['cost'];
                $this->addFinding(
                    'wasted_spend',
                    'high',
                    "Wasted Spend: {$campaign['name']}",
                    "\${$campaign['cost']} spent with 0 conversions in the last 30 days.",
                    $campaign['cost']
                );
            }

            // Low CTR (below 1% for search)
            if ($campaign['channel_type'] == 2 && $campaign['impressions'] > 500 && $campaign['ctr'] < 0.01) {
                $ctrPercent = round($campaign['ctr'] * 100, 2);
                $this->addFinding(
                    'low_ctr',
                    'medium',
                    "Low CTR: {$campaign['name']}",
                    "CTR of {$ctrPercent}% is below the 1% benchmark for search campaigns."
                );
            }
        }

        if ($activeCampaigns === 0 && !empty($campaigns)) {
            $this->addFinding('no_active', 'medium', 'No Active Campaigns', 'All campaigns are paused or disabled.');
        }

        return [
            'total_campaigns' => count($campaigns),
            'active_campaigns' => $activeCampaigns,
            'total_spend_30d' => array_sum(array_column($campaigns, 'cost')),
            'total_conversions_30d' => array_sum(array_column($campaigns, 'conversions')),
            'wasted_spend_30d' => $totalWastedSpend,
        ];
    }

    /**
     * Audit Google ad approval status.
     */
    protected function auditGoogleAdStatus(Customer $customer, string $customerId): array
    {
        $service = new class($customer) extends BaseGoogleAdsService {
            public function getAdApprovalStatus(string $customerId): array
            {
                $this->ensureClient();

                $query = "SELECT " .
                    "ad_group_ad.status, " .
                    "ad_group_ad.policy_summary.approval_status, " .
                    "ad_group_ad.policy_summary.review_status, " .
                    "ad_group_ad.ad.type " .
                    "FROM ad_group_ad " .
                    "WHERE ad_group_ad.status != 'REMOVED'";

                $results = ['total' => 0, 'disapproved' => 0, 'limited' => 0, 'ad_types' => []];
                $response = $this->searchQuery($customerId, $query);
                foreach ($response->getIterator() as $row) {
                    $ad = $row->getAdGroupAd();
                    $results['total']++;

                    $approvalStatus = $ad->getPolicySummary()?->getApprovalStatus();
                    if ($approvalStatus == 3) { // DISAPPROVED
                        $results['disapproved']++;
                    } elseif ($approvalStatus == 4) { // APPROVED_LIMITED
                        $results['limited']++;
                    }

                    $adType = $ad->getAd()->getType();
                    $results['ad_types'][$adType] = ($results['ad_types'][$adType] ?? 0) + 1;
                }
                return $results;
            }
        };

        $adStatus = $service->getAdApprovalStatus($customerId);

        if ($adStatus['disapproved'] > 0) {
            $this->addFinding(
                'disapproved_ads',
                'critical',
                "{$adStatus['disapproved']} Disapproved Ad(s)",
                "You have {$adStatus['disapproved']} ads that have been disapproved by Google and are not running."
            );
        }

        if ($adStatus['limited'] > 0) {
            $this->addFinding(
                'limited_ads',
                'medium',
                "{$adStatus['limited']} Ad(s) With Limited Approval",
                "Some ads have policy restrictions limiting where they can show."
            );
        }

        // Check for missing responsive search ads (type 15)
        $hasRSA = ($adStatus['ad_types'][15] ?? 0) > 0;
        if (!$hasRSA && $adStatus['total'] > 0) {
            $this->addFinding(
                'no_rsa',
                'high',
                'No Responsive Search Ads',
                'Your account has no Responsive Search Ads. Google strongly recommends using RSAs for optimal performance.'
            );
        }

        return $adStatus;
    }

    /**
     * Audit Google conversion tracking setup.
     */
    protected function auditGoogleConversionTracking(Customer $customer, string $customerId): array
    {
        try {
            $service = new ConversionTrackingService($customer);
            $hasConversions = $service->isConversionTrackingSetUp($customerId);

            if (!$hasConversions) {
                $this->addFinding(
                    'no_conversion_tracking',
                    'critical',
                    'No Conversion Tracking',
                    'No conversion actions are set up. You cannot measure ROI without conversion tracking.'
                );
            }

            return ['has_conversion_tracking' => $hasConversions];
        } catch (\Exception $e) {
            Log::warning("AccountAuditAgent: Could not check conversion tracking", [
                'error' => $e->getMessage(),
            ]);
            return ['has_conversion_tracking' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Audit ad group structure (single-ad ad groups, etc).
     */
    protected function auditGoogleAdGroupStructure(Customer $customer, string $customerId): array
    {
        $service = new class($customer) extends BaseGoogleAdsService {
            public function getAdGroupAdCounts(string $customerId): array
            {
                $this->ensureClient();

                $query = "SELECT " .
                    "ad_group.id, " .
                    "ad_group.name, " .
                    "ad_group.status " .
                    "FROM ad_group_ad " .
                    "WHERE ad_group.status = 'ENABLED' " .
                    "AND ad_group_ad.status = 'ENABLED'";

                $adGroupCounts = [];
                $response = $this->searchQuery($customerId, $query);
                foreach ($response->getIterator() as $row) {
                    $adGroup = $row->getAdGroup();
                    $id = $adGroup->getId();
                    if (!isset($adGroupCounts[$id])) {
                        $adGroupCounts[$id] = ['name' => $adGroup->getName(), 'count' => 0];
                    }
                    $adGroupCounts[$id]['count']++;
                }
                return $adGroupCounts;
            }
        };

        $adGroupCounts = $service->getAdGroupAdCounts($customerId);
        $singleAdGroups = 0;

        foreach ($adGroupCounts as $adGroup) {
            if ($adGroup['count'] < 2) {
                $singleAdGroups++;
            }
        }

        if ($singleAdGroups > 0) {
            $total = count($adGroupCounts);
            $this->addFinding(
                'single_ad_groups',
                'medium',
                "{$singleAdGroups} Ad Group(s) With Only 1 Ad",
                "You have {$singleAdGroups} out of {$total} ad groups with only one ad. Google recommends at least 2-3 ads per ad group for A/B testing."
            );
        }

        return [
            'total_ad_groups' => count($adGroupCounts),
            'single_ad_groups' => $singleAdGroups,
        ];
    }

    /**
     * Audit keyword health — broad match without negatives, etc.
     */
    protected function auditGoogleKeywords(Customer $customer, string $customerId): array
    {
        $service = new class($customer) extends BaseGoogleAdsService {
            public function getKeywordAnalysis(string $customerId): array
            {
                $this->ensureClient();

                // Check keyword match types
                $query = "SELECT " .
                    "ad_group_criterion.keyword.match_type, " .
                    "ad_group_criterion.keyword.text, " .
                    "metrics.impressions, " .
                    "metrics.clicks, " .
                    "metrics.cost_micros, " .
                    "metrics.conversions " .
                    "FROM keyword_view " .
                    "WHERE segments.date DURING LAST_30_DAYS " .
                    "AND ad_group_criterion.status = 'ENABLED'";

                $keywords = ['broad' => 0, 'phrase' => 0, 'exact' => 0, 'total_spend' => 0, 'broad_no_convert' => 0];
                $response = $this->searchQuery($customerId, $query);
                foreach ($response->getIterator() as $row) {
                    $matchType = $row->getAdGroupCriterion()->getKeyword()->getMatchType();
                    $cost = $row->getMetrics()->getCostMicros() / 1_000_000;
                    $conversions = $row->getMetrics()->getConversions();

                    $keywords['total_spend'] += $cost;

                    if ($matchType == 4) { // BROAD
                        $keywords['broad']++;
                        if ($conversions == 0 && $cost > 5) {
                            $keywords['broad_no_convert']++;
                        }
                    } elseif ($matchType == 3) { // PHRASE
                        $keywords['phrase']++;
                    } elseif ($matchType == 2) { // EXACT
                        $keywords['exact']++;
                    }
                }

                // Check negative keywords
                $negQuery = "SELECT shared_set.name FROM shared_set WHERE shared_set.type = 'NEGATIVE_KEYWORDS'";
                $keywords['negative_lists'] = 0;
                try {
                    $response = $this->searchQuery($customerId, $negQuery);
                    foreach ($response->getIterator() as $row) {
                        $keywords['negative_lists']++;
                    }
                } catch (\Exception $e) {
                    // Shared sets may not exist — not an error
                }

                return $keywords;
            }
        };

        $keywordData = $service->getKeywordAnalysis($customerId);

        $totalKeywords = $keywordData['broad'] + $keywordData['phrase'] + $keywordData['exact'];

        if ($keywordData['broad'] > 0 && $keywordData['negative_lists'] === 0) {
            $this->addFinding(
                'broad_no_negatives',
                'high',
                'Broad Match Keywords Without Negative Lists',
                "You have {$keywordData['broad']} broad match keywords but no negative keyword lists. This means you're likely paying for irrelevant search terms."
            );
        }

        if ($keywordData['broad_no_convert'] > 0) {
            $this->addFinding(
                'broad_no_conversions',
                'medium',
                "{$keywordData['broad_no_convert']} Broad Keywords Spending Without Converting",
                "These broad match keywords are spending money but haven't generated any conversions in the last 30 days."
            );
        }

        return [
            'total_keywords' => $totalKeywords,
            'broad' => $keywordData['broad'],
            'phrase' => $keywordData['phrase'],
            'exact' => $keywordData['exact'],
            'negative_lists' => $keywordData['negative_lists'],
        ];
    }

    /**
     * Audit ad extensions presence.
     */
    protected function auditGoogleExtensions(Customer $customer, string $customerId): array
    {
        $service = new class($customer) extends BaseGoogleAdsService {
            public function getExtensions(string $customerId): array
            {
                $this->ensureClient();

                $query = "SELECT " .
                    "asset.type " .
                    "FROM asset " .
                    "WHERE asset.type IN ('SITELINK', 'CALLOUT', 'STRUCTURED_SNIPPET', 'CALL', 'PROMOTION')";

                $extensions = [];
                try {
                    $response = $this->searchQuery($customerId, $query);
                    foreach ($response->getIterator() as $row) {
                        $type = $row->getAsset()->getType();
                        $extensions[$type] = ($extensions[$type] ?? 0) + 1;
                    }
                } catch (\Exception $e) {
                    // Some accounts may not have any assets
                }

                return $extensions;
            }
        };

        $extensions = $service->getExtensions($customerId);

        $missingSitelinks = !isset($extensions[17]); // SITELINK type
        $missingCallouts = !isset($extensions[18]); // CALLOUT type

        if ($missingSitelinks) {
            $this->addFinding(
                'no_sitelinks',
                'high',
                'No Sitelink Extensions',
                'Sitelinks can increase CTR by 10-15%. Your account has none configured.'
            );
        }

        if ($missingCallouts) {
            $this->addFinding(
                'no_callouts',
                'medium',
                'No Callout Extensions',
                'Callout extensions highlight key selling points and are missing from your account.'
            );
        }

        return [
            'sitelinks' => $extensions[17] ?? 0,
            'callouts' => $extensions[18] ?? 0,
            'structured_snippets' => $extensions[19] ?? 0,
            'calls' => $extensions[20] ?? 0,
        ];
    }

    // =========================================================================
    // Facebook Ads Audit
    // =========================================================================

    /**
     * Audit a Facebook Ads account.
     */
    protected function auditFacebookAds(AuditSession $auditSession): array
    {
        $accessToken = Crypt::decryptString($auditSession->access_token_encrypted);
        $adAccountId = $auditSession->facebook_ad_account_id;
        $metrics = [];

        // Build services from the audit session's access token
        $campaignService = CampaignService::fromAccessToken($accessToken);
        $insightService = InsightService::fromAccessToken($accessToken);
        $adSetService = AdSetService::fromAccessToken($accessToken);
        $adService = AdService::fromAccessToken($accessToken);
        $accountService = AdAccountService::fromAccessToken($accessToken);

        try {
            // 1. Campaign performance & fatigue
            $metrics['campaigns'] = $this->auditFacebookCampaigns($campaignService, $insightService, $adAccountId);

            // 2. Ad set health
            $metrics['ad_sets'] = $this->auditFacebookAdSets($adSetService, $insightService, $adAccountId);

            // 3. Creative variation
            $metrics['creatives'] = $this->auditFacebookCreatives($adService, $insightService, $adAccountId);

            // 4. Pixel health
            $metrics['pixel'] = $this->auditFacebookPixel($accountService, $adAccountId);

        } catch (\Exception $e) {
            Log::error("AccountAuditAgent: Facebook Ads audit error", [
                'session_id' => $auditSession->id,
                'error' => $e->getMessage(),
            ]);
            $this->addFinding('error', 'critical', 'Audit Error', 'Could not fully audit the account: ' . $e->getMessage());
        }

        return $metrics;
    }

    /**
     * Audit Facebook campaign performance and frequency/fatigue.
     */
    protected function auditFacebookCampaigns(CampaignService $campaignService, InsightService $insightService, string $adAccountId): array
    {
        // CampaignService expects account ID without 'act_' prefix
        $rawAccountId = str_replace('act_', '', $adAccountId);
        $campaigns = $campaignService->listCampaigns($rawAccountId) ?? [];

        $dateStart = Carbon::now()->subDays(30)->format('Y-m-d');
        $dateEnd = Carbon::now()->format('Y-m-d');

        $insights = $insightService->getAccountInsightsByLevel(
            $adAccountId,
            $dateStart,
            $dateEnd,
            'campaign',
            ['campaign_id', 'campaign_name', 'impressions', 'clicks', 'spend', 'actions', 'frequency', 'reach', 'cpc', 'cpm']
        );

        $totalSpend = 0;
        $totalWasted = 0;

        foreach ($insights as $insight) {
            $spend = (float) ($insight['spend'] ?? 0);
            $totalSpend += $spend;
            $frequency = (float) ($insight['frequency'] ?? 0);

            // Check for ad fatigue (frequency > 3)
            if ($frequency > 3) {
                $campaignName = $insight['campaign_name'] ?? 'Unknown';
                $this->addFinding(
                    'ad_fatigue',
                    'high',
                    "Ad Fatigue: {$campaignName}",
                    "Frequency of {$frequency}x means your audience has seen these ads too many times. Performance typically declines above 3x."
                );
            }

            // Wasted spend: campaigns with spend but no conversions
            $conversions = 0;
            foreach ($insight['actions'] ?? [] as $action) {
                if (in_array($action['action_type'], ['purchase', 'lead', 'complete_registration', 'offsite_conversion.fb_pixel_purchase'])) {
                    $conversions += (int) $action['value'];
                }
            }

            if ($spend > 20 && $conversions === 0) {
                $totalWasted += $spend;
                $campaignName = $insight['campaign_name'] ?? 'Unknown';
                $this->addFinding(
                    'wasted_spend',
                    'high',
                    "Wasted Spend: {$campaignName}",
                    "\${$spend} spent with 0 conversions in the last 30 days.",
                    $spend
                );
            }
        }

        $activeCampaigns = count(array_filter($campaigns, fn($c) => $c['status'] === 'ACTIVE'));

        return [
            'total_campaigns' => count($campaigns),
            'active_campaigns' => $activeCampaigns,
            'total_spend_30d' => round($totalSpend, 2),
            'wasted_spend_30d' => round($totalWasted, 2),
        ];
    }

    /**
     * Audit Facebook ad sets — dead delivery, learning limited, budget conflicts.
     */
    protected function auditFacebookAdSets(AdSetService $adSetService, InsightService $insightService, string $adAccountId): array
    {
        $adSets = $adSetService->listAdSetsByAccount($adAccountId, [
            ['field' => 'effective_status', 'operator' => 'IN', 'value' => ['ACTIVE', 'PAUSED']],
        ]);

        $deadAdSets = 0;
        $learningLimited = 0;

        $dateStart = Carbon::now()->subDays(7)->format('Y-m-d');
        $dateEnd = Carbon::now()->format('Y-m-d');

        $insightsData = $insightService->getAccountInsightsByLevel(
            $adAccountId,
            $dateStart,
            $dateEnd,
            'adset',
            ['adset_id', 'impressions', 'spend', 'actions', 'clicks']
        );

        $insights = collect($insightsData)->keyBy('adset_id');

        foreach ($adSets as $adSet) {
            if ($adSet['effective_status'] === 'ACTIVE') {
                $adSetInsight = $insights->get($adSet['id']);
                if (!$adSetInsight || (int) ($adSetInsight['impressions'] ?? 0) === 0) {
                    $deadAdSets++;
                }
            }

            // Check for learning limited — ad sets with insufficient conversions for optimization
            if ($adSet['effective_status'] === 'ACTIVE') {
                $adSetInsight = $insights->get($adSet['id']);
                $conversions = 0;
                foreach (($adSetInsight['actions'] ?? []) as $action) {
                    if (in_array($action['action_type'], ['purchase', 'lead', 'complete_registration'])) {
                        $conversions += (int) ($action['value'] ?? 0);
                    }
                }
                // Less than ~50 conversions/week suggests learning limited
                if ($conversions > 0 && $conversions < 10) {
                    $learningLimited++;
                }
            }
        }

        if ($deadAdSets > 0) {
            $this->addFinding(
                'dead_ad_sets',
                'medium',
                "{$deadAdSets} Active Ad Set(s) With Zero Delivery",
                "These ad sets are set to active but haven't delivered any impressions in the last 7 days."
            );
        }

        if ($learningLimited > 0) {
            $this->addFinding(
                'learning_limited',
                'medium',
                "{$learningLimited} Ad Set(s) Likely Learning-Limited",
                "These ad sets had fewer than 10 conversion events in 7 days. Facebook needs ~50 conversions/week to optimize effectively. Consider broadening targeting or switching to an upper-funnel objective."
            );
        }

        return [
            'total_ad_sets' => count($adSets),
            'dead_ad_sets' => $deadAdSets,
            'learning_limited' => $learningLimited,
        ];
    }

    /**
     * Audit Facebook creative variation — single-ad ad sets, per-ad frequency and CTR.
     */
    protected function auditFacebookCreatives(AdService $adService, InsightService $insightService, string $adAccountId): array
    {
        $ads = $adService->listAdsByAccount($adAccountId, [
            ['field' => 'effective_status', 'operator' => 'IN', 'value' => ['ACTIVE']],
        ]);

        // Count ads per ad set
        $adSetAdCounts = [];
        foreach ($ads as $ad) {
            $adSetId = $ad['adset_id'];
            $adSetAdCounts[$adSetId] = ($adSetAdCounts[$adSetId] ?? 0) + 1;
        }

        $singleAdSets = count(array_filter($adSetAdCounts, fn($count) => $count < 2));

        if ($singleAdSets > 0 && count($adSetAdCounts) > 0) {
            $this->addFinding(
                'no_creative_variation',
                'medium',
                "{$singleAdSets} Ad Set(s) With Only 1 Ad",
                "Testing multiple ad creatives per ad set is essential for finding what resonates with your audience."
            );
        }

        // Per-ad frequency check (high frequency = creative fatigue)
        $highFrequencyAds = 0;
        if (!empty($ads)) {
            $adIds = array_column($ads, 'id');
            $dateStart = Carbon::now()->subDays(14)->format('Y-m-d');
            $dateEnd = Carbon::now()->format('Y-m-d');

            // Check up to 50 ads to avoid rate limits
            foreach (array_slice($adIds, 0, 50) as $adId) {
                $adInsights = $insightService->getAdInsights(
                    $adId,
                    $dateStart,
                    $dateEnd,
                    ['frequency', 'impressions', 'clicks']
                );
                $data = $adInsights[0] ?? null;
                if ($data && (float) ($data['frequency'] ?? 0) > 4.0) {
                    $highFrequencyAds++;
                }
            }

            if ($highFrequencyAds > 0) {
                $this->addFinding(
                    'high_ad_frequency',
                    'high',
                    "{$highFrequencyAds} Ad(s) With Frequency Above 4x",
                    "These ads have been shown to users more than 4 times in 14 days. Refresh creatives to prevent ad fatigue and declining performance."
                );
            }
        }

        return [
            'total_active_ads' => count($ads),
            'single_ad_sets' => $singleAdSets,
            'total_ad_sets_with_ads' => count($adSetAdCounts),
            'high_frequency_ads' => $highFrequencyAds,
        ];
    }

    /**
     * Audit Facebook pixel health — existence, recency, and event coverage.
     */
    protected function auditFacebookPixel(AdAccountService $accountService, string $adAccountId): array
    {
        $pixels = $accountService->getPixels($adAccountId);

        if (empty($pixels)) {
            $this->addFinding(
                'no_pixel',
                'critical',
                'No Facebook Pixel Installed',
                'Without a pixel, you cannot track conversions, build custom audiences, or optimize for conversions.'
            );
            return ['has_pixel' => false, 'event_types' => []];
        }

        $pixelId = $pixels[0]['id'];

        // Check if pixel has fired recently
        $lastFired = $pixels[0]['last_fired_time'] ?? null;
        if ($lastFired) {
            $lastFiredDate = Carbon::parse($lastFired);
            if ($lastFiredDate->lt(Carbon::now()->subDays(7))) {
                $this->addFinding(
                    'pixel_inactive',
                    'high',
                    'Facebook Pixel Not Firing',
                    "Your pixel hasn't fired in over 7 days. It may not be installed correctly on your website."
                );
            }
        } else {
            $this->addFinding(
                'pixel_never_fired',
                'critical',
                'Facebook Pixel Has Never Fired',
                'Your pixel exists but has never been triggered. Verify it is installed on your website.'
            );
        }

        // Check pixel event stats for the last 7 days
        $eventTypes = [];
        try {
            $stats = $accountService->getPixelStats($pixelId);

            foreach ($stats as $stat) {
                $eventTypes[] = $stat['event'] ?? $stat['name'] ?? 'unknown';
            }

            // Check for missing key conversion events
            $keyEvents = ['Purchase', 'Lead', 'CompleteRegistration', 'AddToCart', 'InitiateCheckout'];
            $hasConversionEvent = !empty(array_intersect($keyEvents, $eventTypes));

            if (!$hasConversionEvent && !empty($eventTypes)) {
                $this->addFinding(
                    'missing_conversion_events',
                    'high',
                    'No Key Conversion Events Detected',
                    'Your pixel is firing page views but not tracking conversion events (Purchase, Lead, etc.). Set up standard events to enable conversion optimization.'
                );
            }
        } catch (\Exception $e) {
            Log::debug("AccountAuditAgent: Could not fetch pixel stats", ['error' => $e->getMessage()]);
        }

        return [
            'has_pixel' => true,
            'pixel_count' => count($pixels),
            'event_types' => $eventTypes,
        ];
    }

    // =========================================================================
    // Common Methods
    // =========================================================================

    /**
     * Add a finding to the audit results.
     */
    protected function addFinding(string $category, string $severity, string $title, string $description, float $estimatedImpact = 0): void
    {
        $this->findings[] = [
            'category' => $category,
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
            'estimated_impact' => $estimatedImpact,
        ];

        // Deduct from score
        $this->score -= $this->severityWeights[$severity] ?? 5;
    }

    /**
     * Generate a text summary of the audit.
     */
    protected function generateSummary(): array
    {
        $critical = count(array_filter($this->findings, fn($f) => $f['severity'] === 'critical'));
        $high = count(array_filter($this->findings, fn($f) => $f['severity'] === 'high'));
        $medium = count(array_filter($this->findings, fn($f) => $f['severity'] === 'medium'));
        $low = count(array_filter($this->findings, fn($f) => $f['severity'] === 'low'));
        $totalWasted = array_sum(array_column($this->findings, 'estimated_impact'));

        return [
            'total_findings' => count($this->findings),
            'critical' => $critical,
            'high' => $high,
            'medium' => $medium,
            'low' => $low,
            'estimated_wasted_spend' => round($totalWasted, 2),
        ];
    }

    /**
     * Use Gemini to generate actionable recommendations from findings.
     */
    protected function generateRecommendations(array $findings, string $platform): array
    {
        if (empty($findings)) {
            return [['title' => 'Account looks healthy!', 'description' => 'No major issues found. Keep monitoring performance regularly.']];
        }

        $findingsText = collect($findings)->map(function ($f) {
            return "- [{$f['severity']}] {$f['title']}: {$f['description']}";
        })->implode("\n");

        $prompt = "You are an expert {$platform} advertising consultant. Based on these audit findings, generate 3-5 prioritized, actionable recommendations. Each recommendation should be specific and immediately implementable.\n\nFindings:\n{$findingsText}\n\nRespond with a JSON array of objects, each with 'title' (short action title), 'description' (2-3 sentence explanation), and 'priority' (1-5, 1 being highest). Only return the JSON array, no other text.";

        try {
            $response = $this->gemini->generateContent(
                'gemini-3-flash-preview',
                $prompt,
                ['temperature' => 0.3, 'maxOutputTokens' => 1500]
            );

            $text = $response['text'] ?? '';
            // Extract JSON from response
            if (preg_match('/\[.*\]/s', $text, $matches)) {
                $recommendations = json_decode($matches[0], true);
                if (is_array($recommendations)) {
                    usort($recommendations, fn($a, $b) => ($a['priority'] ?? 5) - ($b['priority'] ?? 5));
                    return $recommendations;
                }
            }
        } catch (\Exception $e) {
            Log::warning("AccountAuditAgent: Could not generate AI recommendations", [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: return findings as recommendations
        return collect($findings)
            ->sortByDesc(fn($f) => $this->severityWeights[$f['severity']] ?? 0)
            ->take(5)
            ->map(fn($f) => ['title' => "Fix: {$f['title']}", 'description' => $f['description'], 'priority' => match ($f['severity']) {
                'critical' => 1, 'high' => 2, 'medium' => 3, default => 4,
            }])
            ->values()
            ->toArray();
    }
}
