<?php

namespace App\Services\Testing;

use App\Models\AgentActivity;
use App\Models\AttributionConversion;
use App\Models\AttributionTouchpoint;
use App\Models\Campaign;
use App\Models\CampaignHourlyPerformance;
use App\Models\Competitor;
use App\Models\Customer;
use App\Models\GoogleAdsPerformanceData;
use App\Models\FacebookAdsPerformanceData;
use App\Models\MicrosoftAdsPerformanceData;
use App\Models\LinkedInAdsPerformanceData;
use App\Models\Keyword;
use App\Models\KeywordQualityScore;
use App\Models\User;
use Illuminate\Support\Str;

class SyntheticDataService
{
    /**
     * Campaign scenario definitions — each models a realistic problem state.
     */
    protected array $scenarios = [
        'google_search_healthy' => [
            'name' => 'Google Search — Brand + Non-Brand',
            'platform' => 'google',
            'reason' => 'Drive high-intent search traffic for core product terms',
            'goals' => ['Maximize conversions', 'Maintain ROAS above 4x'],
            'daily_budget' => 85.00,
            'metrics' => ['ctr' => 0.065, 'cpc' => 1.80, 'conv_rate' => 0.042, 'cpa' => 42.00],
            'trend' => 'stable',
            'problem' => null,
        ],
        'google_pmax_declining' => [
            'name' => 'Google PMax — Product Feed',
            'platform' => 'google',
            'reason' => 'Automated Performance Max campaign for product catalog',
            'goals' => ['Drive online sales', 'Expand reach via automation'],
            'daily_budget' => 120.00,
            'metrics' => ['ctr' => 0.028, 'cpc' => 0.95, 'conv_rate' => 0.018, 'cpa' => 55.00],
            'trend' => 'declining',
            'problem' => 'Conversions dropped 40% over last 7 days while spend remained flat',
        ],
        'facebook_retargeting_fatigue' => [
            'name' => 'Facebook — Retargeting Warm Audience',
            'platform' => 'facebook',
            'reason' => 'Retarget website visitors and cart abandoners',
            'goals' => ['Recover abandoned carts', 'Reduce CPA below $35'],
            'daily_budget' => 65.00,
            'metrics' => ['ctr' => 0.012, 'cpc' => 2.40, 'conv_rate' => 0.015, 'cpa' => 68.00],
            'trend' => 'declining',
            'problem' => 'Creative fatigue — frequency above 6.0, CTR halved in 2 weeks',
        ],
        'linkedin_b2b_overspend' => [
            'name' => 'LinkedIn — B2B Decision Makers',
            'platform' => 'linkedin',
            'reason' => 'Target C-suite and VP-level prospects in SaaS vertical',
            'goals' => ['Generate qualified leads', 'Build brand awareness'],
            'daily_budget' => 95.00,
            'metrics' => ['ctr' => 0.004, 'cpc' => 12.50, 'conv_rate' => 0.008, 'cpa' => 185.00],
            'trend' => 'overspending',
            'problem' => 'Spending 120% of daily budget with CPA 2x above target',
        ],
        'microsoft_search_low_qs' => [
            'name' => 'Microsoft Search — Imported from Google',
            'platform' => 'microsoft',
            'reason' => 'Extend search presence to Bing/Edge audience',
            'goals' => ['Incremental reach', 'Match Google ROAS performance'],
            'daily_budget' => 45.00,
            'metrics' => ['ctr' => 0.032, 'cpc' => 1.45, 'conv_rate' => 0.022, 'cpa' => 65.00],
            'trend' => 'underperforming',
            'problem' => 'Low quality scores (avg 4.2) dragging up CPCs — imported ads not optimized for Bing',
        ],
    ];

    /**
     * Generate a complete sandbox environment for a user.
     */
    public function generateSandboxForUser(User $user): Customer
    {
        $this->cleanupUserSandbox($user);

        $customer = Customer::create([
            'name' => 'Sandbox Demo — ' . $user->name,
            'business_type' => 'E-Commerce',
            'description' => 'Sandbox simulation customer for testing optimization agents',
            'industry' => 'Technology / SaaS',
            'country' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
            'website' => 'https://sandbox-demo.example.com',
            'google_ads_customer_id' => 'sandbox-' . Str::random(10),
            'facebook_ads_account_id' => 'sandbox-' . Str::random(10),
            'microsoft_ads_customer_id' => 'sandbox-' . Str::random(10),
            'microsoft_ads_account_id' => 'sandbox-' . Str::random(10),
            'linkedin_ads_account_id' => 'sandbox-' . Str::random(10),
            'average_order_value' => 95.00,
            'is_sandbox' => true,
            'sandbox_expires_at' => now()->addDays(7),
        ]);

        $customer->users()->attach($user->id, ['role' => 'owner']);

        $campaigns = $this->generateCampaigns($customer);

        foreach ($campaigns as $scenario => $campaign) {
            $config = $this->scenarios[$scenario];
            $this->generateDailyPerformance($campaign, $config, 30);
            $this->generateHourlyPerformance($campaign, $config, 7);

            if (in_array($config['platform'], ['google', 'microsoft'])) {
                $this->generateKeywords($campaign, $config);
                $this->generateKeywordQualityScores($campaign, $config);
            }
        }

        $this->generateAttributionData($customer, $campaigns);
        $this->generateCompetitorData($customer);

        return $customer;
    }

    /**
     * Generate 5 campaigns across platforms.
     */
    public function generateCampaigns(Customer $customer): array
    {
        $campaigns = [];

        foreach ($this->scenarios as $key => $config) {
            $platformId = 'sandbox_' . Str::random(12);

            $campaignData = [
                'customer_id' => $customer->id,
                'name' => $config['name'],
                'reason' => $config['reason'],
                'goals' => $config['goals'],
                'target_market' => 'US adults 25-54, interested in technology and productivity tools',
                'voice' => 'Professional yet approachable',
                'total_budget' => $config['daily_budget'] * 30,
                'daily_budget' => $config['daily_budget'],
                'start_date' => now()->subDays(30)->toDateString(),
                'end_date' => now()->addDays(30)->toDateString(),
                'primary_kpi' => 'conversions',
                'platform_status' => 'ENABLED',
                'primary_status' => 'eligible',
                'geographic_targeting' => ['countries' => ['US']],
            ];

            match ($config['platform']) {
                'google' => $campaignData['google_ads_campaign_id'] = $platformId,
                'facebook' => $campaignData['facebook_ads_campaign_id'] = $platformId,
                'microsoft' => $campaignData['microsoft_ads_campaign_id'] = $platformId,
                'linkedin' => $campaignData['linkedin_campaign_id'] = $platformId,
            };

            $campaigns[$key] = Campaign::create($campaignData);
        }

        return $campaigns;
    }

    /**
     * Generate realistic daily performance data with scenario-specific patterns.
     */
    public function generateDailyPerformance(Campaign $campaign, array $config, int $days = 30): void
    {
        $modelClass = match ($config['platform']) {
            'google' => GoogleAdsPerformanceData::class,
            'facebook' => FacebookAdsPerformanceData::class,
            'microsoft' => MicrosoftAdsPerformanceData::class,
            'linkedin' => LinkedInAdsPerformanceData::class,
        };

        $baseCtr = $config['metrics']['ctr'];
        $baseCpc = $config['metrics']['cpc'];
        $baseConvRate = $config['metrics']['conv_rate'];

        for ($i = $days; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $dayOfWeek = $date->dayOfWeek;

            $weekendMod = in_array($dayOfWeek, [0, 6]) ? 0.75 : 1.0;
            $trendMod = $this->getTrendModifier($config['trend'], $i, $days);
            $noise = 1.0 + (mt_rand(-150, 150) / 1000);

            $impressions = (int) round(($config['daily_budget'] / $baseCpc / $baseCtr) * $weekendMod * $trendMod * $noise);
            $ctr = $baseCtr * $trendMod * $noise;
            $clicks = max(1, (int) round($impressions * $ctr));
            $cpc = $baseCpc * (1 / $trendMod) * $noise;
            $cost = round($clicks * $cpc, 2);
            $conversions = max(0, (int) round($clicks * $baseConvRate * $trendMod * $noise));
            $conversionValue = round($conversions * 95.00 * $noise, 2);

            $record = [
                'campaign_id' => $campaign->id,
                'date' => $date->toDateString(),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'cost' => $cost,
                'conversions' => $conversions,
                'conversion_value' => $conversionValue,
                'ctr' => round($ctr, 4),
                'cpc' => round($cpc, 2),
                'cpa' => $conversions > 0 ? round($cost / $conversions, 2) : 0,
            ];

            if ($config['platform'] === 'facebook') {
                $record['facebook_campaign_id'] = $campaign->facebook_ads_campaign_id;
                $record['reach'] = (int) round($impressions * 0.7);
                $record['frequency'] = $config['trend'] === 'declining' && $config['problem'] && str_contains($config['problem'], 'fatigue')
                    ? round(2.0 + ($days - $i) * 0.15, 1)
                    : round(1.2 + ($days - $i) * 0.03, 1);
                $record['cpm'] = $impressions > 0 ? round(($cost / $impressions) * 1000, 2) : 0;
            }

            $modelClass::create($record);
        }
    }

    /**
     * Generate hourly performance data for BudgetIntelligenceAgent.
     */
    public function generateHourlyPerformance(Campaign $campaign, array $config, int $days = 7): void
    {
        $platformMap = [
            'google' => 'google_ads',
            'facebook' => 'facebook_ads',
            'microsoft' => 'microsoft_ads',
            'linkedin' => 'linkedin_ads',
        ];

        $hourlyWeights = [
            0.02, 0.01, 0.01, 0.01, 0.01, 0.02, 0.03, 0.04,
            0.06, 0.08, 0.09, 0.09, 0.08, 0.08, 0.07, 0.06,
            0.05, 0.05, 0.04, 0.03, 0.03, 0.02, 0.02, 0.02,
        ];

        for ($d = $days; $d >= 0; $d--) {
            $date = now()->subDays($d);
            $dayOfWeek = $date->dayOfWeek;

            foreach ($hourlyWeights as $hour => $weight) {
                $noise = 1.0 + (mt_rand(-200, 200) / 1000);
                $hourlyBudget = $config['daily_budget'] * $weight;

                $impressions = max(0, (int) round(($hourlyBudget / $config['metrics']['cpc'] / $config['metrics']['ctr']) * $noise));
                $clicks = max(0, (int) round($impressions * $config['metrics']['ctr'] * $noise));
                $spend = round($clicks * $config['metrics']['cpc'] * $noise, 2);
                $conversions = max(0, (int) round($clicks * $config['metrics']['conv_rate'] * $noise));
                $conversionValue = round($conversions * 95.00, 2);

                CampaignHourlyPerformance::create([
                    'campaign_id' => $campaign->id,
                    'customer_id' => $campaign->customer_id,
                    'date' => $date->toDateString(),
                    'hour' => $hour,
                    'day_of_week' => $dayOfWeek,
                    'platform' => $platformMap[$config['platform']],
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'conversions' => $conversions,
                    'spend' => $spend,
                    'conversion_value' => $conversionValue,
                    'ctr' => $impressions > 0 ? round($clicks / $impressions, 4) : 0,
                    'roas' => $spend > 0 ? round($conversionValue / $spend, 2) : 0,
                ]);
            }
        }
    }

    /**
     * Generate keywords for search campaigns.
     */
    public function generateKeywords(Campaign $campaign, array $config): void
    {
        $keywordSets = [
            'google' => [
                ['text' => 'project management software', 'match' => 'BROAD', 'intent' => 'commercial', 'qs' => 8],
                ['text' => 'best task management tool', 'match' => 'PHRASE', 'intent' => 'commercial', 'qs' => 7],
                ['text' => 'team collaboration platform', 'match' => 'BROAD', 'intent' => 'informational', 'qs' => 6],
                ['text' => 'free project tracker', 'match' => 'BROAD', 'intent' => 'transactional', 'qs' => 4],
                ['text' => 'enterprise workflow automation', 'match' => 'EXACT', 'intent' => 'commercial', 'qs' => 9],
                ['text' => 'buy project management app', 'match' => 'PHRASE', 'intent' => 'transactional', 'qs' => 8],
                ['text' => 'kanban board online', 'match' => 'BROAD', 'intent' => 'informational', 'qs' => 5],
                ['text' => 'agile sprint planning tool', 'match' => 'EXACT', 'intent' => 'commercial', 'qs' => 7],
            ],
            'microsoft' => [
                ['text' => 'project management software', 'match' => 'BROAD', 'intent' => 'commercial', 'qs' => 4],
                ['text' => 'team task tracker', 'match' => 'PHRASE', 'intent' => 'commercial', 'qs' => 3],
                ['text' => 'online collaboration tool', 'match' => 'BROAD', 'intent' => 'informational', 'qs' => 4],
                ['text' => 'workflow management platform', 'match' => 'EXACT', 'intent' => 'commercial', 'qs' => 5],
                ['text' => 'project planning software free', 'match' => 'BROAD', 'intent' => 'transactional', 'qs' => 3],
                ['text' => 'enterprise project tools', 'match' => 'PHRASE', 'intent' => 'commercial', 'qs' => 4],
            ],
        ];

        $keywords = $keywordSets[$config['platform']] ?? [];

        foreach ($keywords as $kw) {
            Keyword::create([
                'customer_id' => $campaign->customer_id,
                'campaign_id' => $campaign->id,
                'keyword_text' => $kw['text'],
                'match_type' => $kw['match'],
                'status' => 'active',
                'source' => 'sandbox',
                'quality_score' => $kw['qs'],
                'avg_monthly_searches' => mt_rand(1000, 50000),
                'competition_index' => mt_rand(30, 90),
                'estimated_cpc_micros' => mt_rand(800000, 3500000),
                'ctr' => round(mt_rand(20, 80) / 1000, 3),
                'conversions' => mt_rand(0, 25),
                'cost' => round(mt_rand(500, 5000) / 100, 2),
                'intent' => $kw['intent'],
                'funnel_stage' => match ($kw['intent']) {
                    'transactional' => 'bottom',
                    'commercial' => 'middle',
                    default => 'top',
                },
            ]);
        }
    }

    /**
     * Generate keyword quality score history for trending analysis.
     */
    public function generateKeywordQualityScores(Campaign $campaign, array $config): void
    {
        $keywords = Keyword::where('campaign_id', $campaign->id)->get();

        foreach ($keywords as $keyword) {
            for ($d = 14; $d >= 0; $d--) {
                $baseQs = $keyword->quality_score;
                $qsDrift = $config['trend'] === 'underperforming' ? -($d > 7 ? 0 : 1) : 0;
                $qs = max(1, min(10, $baseQs + $qsDrift + mt_rand(-1, 1)));

                KeywordQualityScore::create([
                    'customer_id' => $campaign->customer_id,
                    'campaign_google_id' => $campaign->google_ads_campaign_id ?? $campaign->microsoft_ads_campaign_id,
                    'keyword_text' => $keyword->keyword_text,
                    'match_type' => $keyword->match_type,
                    'quality_score' => $qs,
                    'creative_quality_score' => max(1, min(10, $qs + mt_rand(-1, 1))),
                    'post_click_quality_score' => max(1, min(10, $qs + mt_rand(-1, 1))),
                    'search_predicted_ctr' => ['BELOW_AVERAGE', 'AVERAGE', 'ABOVE_AVERAGE'][min(2, max(0, (int) floor($qs / 4)))],
                    'impressions' => mt_rand(50, 500),
                    'clicks' => mt_rand(5, 80),
                    'conversions' => mt_rand(0, 8),
                    'cost_micros' => mt_rand(1000000, 15000000),
                    'recorded_at' => now()->subDays($d),
                ]);
            }
        }
    }

    /**
     * Generate attribution touchpoints and conversions.
     */
    public function generateAttributionData(Customer $customer, array $campaigns): void
    {
        $sources = [
            ['utm_source' => 'google', 'utm_medium' => 'cpc'],
            ['utm_source' => 'facebook', 'utm_medium' => 'cpc'],
            ['utm_source' => 'linkedin', 'utm_medium' => 'cpc'],
            ['utm_source' => 'google', 'utm_medium' => 'organic'],
            ['utm_source' => 'direct', 'utm_medium' => 'none'],
        ];

        for ($j = 0; $j < 15; $j++) {
            $visitorId = 'sandbox_visitor_' . Str::random(16);
            $numTouchpoints = mt_rand(2, 5);
            $touchpointRecords = [];

            for ($t = 0; $t < $numTouchpoints; $t++) {
                $source = $sources[array_rand($sources)];
                $daysAgo = mt_rand(1, 25);

                $tp = AttributionTouchpoint::create([
                    'customer_id' => $customer->id,
                    'visitor_id' => $visitorId,
                    'utm_source' => $source['utm_source'],
                    'utm_medium' => $source['utm_medium'],
                    'utm_campaign' => 'spectra_' . array_rand($campaigns),
                    'page_url' => 'https://sandbox-demo.example.com/product?ref=' . Str::random(6),
                    'touched_at' => now()->subDays($daysAgo)->subHours(mt_rand(0, 23)),
                ]);

                $touchpointRecords[] = $tp->toArray();
            }

            $conversionValue = round(mt_rand(3000, 25000) / 100, 2);

            // Simple last-touch attribution for sandbox
            $lastTouchpoint = end($touchpointRecords);
            $attribution = [
                'last_click' => [['source' => $lastTouchpoint['utm_source'] ?? 'direct', 'value' => $conversionValue]],
                'first_click' => [['source' => $touchpointRecords[0]['utm_source'] ?? 'direct', 'value' => $conversionValue]],
                'linear' => array_map(fn($tp) => [
                    'source' => $tp['utm_source'] ?? 'direct',
                    'value' => round($conversionValue / count($touchpointRecords), 2),
                ], $touchpointRecords),
            ];

            AttributionConversion::create([
                'customer_id' => $customer->id,
                'visitor_id' => $visitorId,
                'conversion_type' => ['purchase', 'lead', 'signup'][mt_rand(0, 2)],
                'conversion_value' => $conversionValue,
                'touchpoints' => $touchpointRecords,
                'attributed_to' => $attribution,
            ]);
        }
    }

    /**
     * Generate synthetic competitor data.
     */
    public function generateCompetitorData(Customer $customer): void
    {
        $competitors = [
            [
                'name' => 'Competitor Alpha (Market Leader)',
                'domain' => 'alpha-project.example.com',
                'messaging_analysis' => ['focus' => 'Enterprise security and compliance', 'tone' => 'Corporate', 'social_proof' => true],
                'value_propositions' => ['Enterprise-grade security', '99.9% uptime SLA', 'SOC 2 certified'],
                'impression_share' => 0.35,
                'overlap_rate' => 0.62,
                'position_above_rate' => 0.41,
            ],
            [
                'name' => 'Competitor Beta (Price Disruptor)',
                'domain' => 'beta-tasks.example.com',
                'messaging_analysis' => ['focus' => 'Price-first, free tier prominent', 'tone' => 'Casual', 'social_proof' => false],
                'value_propositions' => ['Free forever plan', 'Simple and intuitive', 'No credit card required'],
                'impression_share' => 0.22,
                'overlap_rate' => 0.48,
                'position_above_rate' => 0.28,
            ],
            [
                'name' => 'Competitor Gamma (Feature Rich)',
                'domain' => 'gamma-work.example.com',
                'messaging_analysis' => ['focus' => 'All-in-one workspace positioning', 'tone' => 'Modern', 'social_proof' => true],
                'value_propositions' => ['All-in-one workspace', '50+ integrations', 'AI-powered automation'],
                'impression_share' => 0.18,
                'overlap_rate' => 0.55,
                'position_above_rate' => 0.22,
            ],
        ];

        foreach ($competitors as $comp) {
            Competitor::create([
                'customer_id' => $customer->id,
                'url' => 'https://' . $comp['domain'],
                'domain' => $comp['domain'],
                'name' => $comp['name'],
                'messaging_analysis' => $comp['messaging_analysis'],
                'value_propositions' => $comp['value_propositions'],
                'impression_share' => $comp['impression_share'],
                'overlap_rate' => $comp['overlap_rate'],
                'position_above_rate' => $comp['position_above_rate'],
                'discovery_source' => 'sandbox',
                'last_analyzed_at' => now(),
            ]);
        }
    }

    /**
     * Remove existing sandbox data for a user.
     */
    public function cleanupUserSandbox(User $user): void
    {
        $sandboxCustomers = Customer::sandbox()
            ->whereHas('users', fn($q) => $q->where('user_id', $user->id))
            ->get();

        foreach ($sandboxCustomers as $customer) {
            $this->deleteSandboxCustomer($customer);
        }
    }

    /**
     * Delete a sandbox customer and all associated data.
     */
    public function deleteSandboxCustomer(Customer $customer): void
    {
        if (!$customer->is_sandbox) return;

        $campaignIds = $customer->campaigns()->pluck('id');

        GoogleAdsPerformanceData::whereIn('campaign_id', $campaignIds)->delete();
        FacebookAdsPerformanceData::whereIn('campaign_id', $campaignIds)->delete();
        MicrosoftAdsPerformanceData::whereIn('campaign_id', $campaignIds)->delete();
        LinkedInAdsPerformanceData::whereIn('campaign_id', $campaignIds)->delete();
        CampaignHourlyPerformance::where('customer_id', $customer->id)->delete();

        Keyword::where('customer_id', $customer->id)->delete();
        KeywordQualityScore::where('customer_id', $customer->id)->delete();

        AttributionTouchpoint::where('customer_id', $customer->id)->delete();
        AttributionConversion::where('customer_id', $customer->id)->delete();

        Competitor::where('customer_id', $customer->id)->delete();
        AgentActivity::where('customer_id', $customer->id)->delete();

        Campaign::whereIn('id', $campaignIds)->delete();

        $customer->users()->detach();
        $customer->delete();
    }

    /**
     * Get trend modifier for a day in the series.
     */
    protected function getTrendModifier(string $trend, int $daysAgo, int $totalDays): float
    {
        return match ($trend) {
            'stable' => 1.0,
            'declining' => $daysAgo <= 7 ? 1.0 - (0.06 * (7 - $daysAgo)) : 1.0,
            'overspending' => 1.0 + (($totalDays - $daysAgo) * 0.005),
            'underperforming' => 0.85,
            default => 1.0,
        };
    }

    /**
     * Get all scenario definitions (for frontend display).
     */
    public function getScenarios(): array
    {
        return $this->scenarios;
    }
}
