<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Customer;
use App\Notifications\CriticalAgentAlert;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\MicrosoftAds\AdGroupService as MicrosoftAdGroupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Weekly job that detects keyword cannibalization — multiple ad groups or campaigns
 * targeting the same query, causing intra-account auction competition.
 */
class DetectKeywordCannibalization implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    public function handle(): void
    {
        Log::info("DetectKeywordCannibalization: Starting weekly cannibalization scan");

        $googleCustomers = Customer::whereNotNull('google_ads_customer_id')
            ->whereHas('campaigns', fn($q) => $q->whereNotNull('google_ads_campaign_id')->where('status', 'active'))
            ->get();

        foreach ($googleCustomers as $customer) {
            try {
                $this->scanCustomer($customer);
            } catch (\Exception $e) {
                Log::error("DetectKeywordCannibalization: Failed for customer {$customer->id}: " . $e->getMessage());
            }
        }

        $microsoftCustomers = Customer::whereNotNull('microsoft_ads_customer_id')
            ->whereHas('campaigns', fn($q) => $q->whereNotNull('microsoft_ads_campaign_id')->where('status', 'active'))
            ->whereNotIn('id', $googleCustomers->pluck('id'))
            ->get();

        foreach ($microsoftCustomers as $customer) {
            try {
                $this->scanMicrosoftCustomer($customer);
            } catch (\Exception $e) {
                Log::error("DetectKeywordCannibalization: Failed (Microsoft) for customer {$customer->id}: " . $e->getMessage());
            }
        }

        Log::info("DetectKeywordCannibalization: Scan complete");
    }

    private function scanCustomer(Customer $customer): void
    {
        $customerId = $customer->cleanGoogleCustomerId();

        $service = new class($customer) extends BaseGoogleAdsService {
            public function fetchAllKeywords(string $customerId): array
            {
                $this->ensureClient();
                $query = "SELECT campaign.resource_name, campaign.name, "
                       . "ad_group.resource_name, ad_group.name, "
                       . "ad_group_criterion.keyword.text "
                       . "FROM ad_group_criterion "
                       . "WHERE ad_group_criterion.type = 'KEYWORD' "
                       . "AND ad_group_criterion.status = 'ENABLED' "
                       . "AND ad_group_criterion.negative = false "
                       . "AND campaign.status = 'ENABLED' "
                       . "AND ad_group.status = 'ENABLED'";

                $results = [];
                foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
                    $results[] = [
                        'campaign_resource' => $row->getCampaign()->getResourceName(),
                        'campaign_name'     => $row->getCampaign()->getName(),
                        'ad_group_resource' => $row->getAdGroup()->getResourceName(),
                        'ad_group_name'     => $row->getAdGroup()->getName(),
                        'keyword'           => strtolower(trim($row->getAdGroupCriterion()->getKeyword()->getText())),
                    ];
                }
                return $results;
            }
        };

        $keywords = $service->fetchAllKeywords($customerId);

        if (empty($keywords)) {
            return;
        }

        // Group by normalized keyword text
        $grouped = [];
        foreach ($keywords as $kw) {
            $grouped[$kw['keyword']][] = $kw;
        }

        $cannibalized = [];
        foreach ($grouped as $text => $entries) {
            if (count($entries) < 2) {
                continue;
            }

            // Check if duplicated across different ad groups
            $adGroups = array_unique(array_column($entries, 'ad_group_resource'));
            $campaigns = array_unique(array_column($entries, 'campaign_resource'));

            if (count($adGroups) >= 2) {
                $cannibalized[] = [
                    'keyword'       => $text,
                    'ad_group_count' => count($adGroups),
                    'campaign_count' => count($campaigns),
                    'locations'     => array_map(fn($e) => $e['campaign_name'] . ' > ' . $e['ad_group_name'], $entries),
                ];
            }
        }

        if (empty($cannibalized)) {
            return;
        }

        $count = count($cannibalized);
        Log::warning("DetectKeywordCannibalization: Found {$count} cannibalized keywords for customer {$customer->id}");

        AgentActivity::record(
            'keyword',
            'keyword_cannibalization',
            "Detected {$count} keyword(s) appearing in multiple ad groups (intra-account competition)",
            $customer->id,
            null,
            [
                'cannibalized' => array_slice($cannibalized, 0, 20),
                'recommendation' => 'Consolidate duplicate keywords into a single ad group or add negatives to prevent intra-account competition.',
            ]
        );

        $cacheKey = "cannibalization_alert:{$customer->id}";
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, now()->addHours(168)); // 7 days

        foreach ($customer->users as $user) {
            $user->notify(new CriticalAgentAlert(
                'keyword_cannibalization',
                'Keyword Cannibalization Detected',
                "{$count} keyword(s) are appearing in multiple ad groups, causing your campaigns to compete against themselves.",
                [
                    'issues' => array_map(
                        fn($c) => "\"{$c['keyword']}\" found in {$c['ad_group_count']} ad groups",
                        array_slice($cannibalized, 0, 5)
                    ),
                    'action_required' => 'Consolidate duplicate keywords into single ad groups and use negatives to prevent overlap.',
                ]
            ));
        }
    }

    private function scanMicrosoftCustomer(Customer $customer): void
    {
        $service = new MicrosoftAdGroupService($customer);
        $keywords = [];

        foreach ($customer->campaigns()->whereNotNull('microsoft_ads_campaign_id')->where('status', 'active')->get() as $campaign) {
            $adGroups = $service->getAdGroupsByCampaignId((string) $campaign->microsoft_ads_campaign_id);

            foreach ($adGroups as $adGroup) {
                $adGroupId = (string) ($adGroup['Id'] ?? '');
                if (!$adGroupId) continue;

                foreach ($service->getKeywordsByAdGroupId($adGroupId) as $kw) {
                    if (($kw['Status'] ?? '') !== 'Active') continue;
                    $keywords[] = [
                        'campaign_resource' => (string) $campaign->microsoft_ads_campaign_id,
                        'campaign_name'     => $campaign->name,
                        'ad_group_resource' => $adGroupId,
                        'ad_group_name'     => $adGroup['Name'] ?? $adGroupId,
                        'keyword'           => strtolower(trim($kw['Text'] ?? '')),
                    ];
                }
            }
        }

        if (empty($keywords)) return;

        $grouped = [];
        foreach ($keywords as $kw) {
            $grouped[$kw['keyword']][] = $kw;
        }

        $cannibalized = [];
        foreach ($grouped as $text => $entries) {
            $adGroups = array_unique(array_column($entries, 'ad_group_resource'));
            if (count($adGroups) >= 2) {
                $campaigns = array_unique(array_column($entries, 'campaign_resource'));
                $cannibalized[] = [
                    'keyword'        => $text,
                    'ad_group_count' => count($adGroups),
                    'campaign_count' => count($campaigns),
                    'locations'      => array_map(fn($e) => $e['campaign_name'] . ' > ' . $e['ad_group_name'], $entries),
                ];
            }
        }

        if (empty($cannibalized)) return;

        $count = count($cannibalized);
        Log::warning("DetectKeywordCannibalization (Microsoft): Found {$count} cannibalized keywords for customer {$customer->id}");

        AgentActivity::record(
            'keyword',
            'keyword_cannibalization',
            "Detected {$count} keyword(s) appearing in multiple ad groups (Microsoft Ads — intra-account competition)",
            $customer->id,
            null,
            [
                'platform'       => 'microsoft_ads',
                'cannibalized'   => array_slice($cannibalized, 0, 20),
                'recommendation' => 'Consolidate duplicate keywords into a single ad group or add negatives to prevent intra-account competition.',
            ]
        );

        $cacheKey = "cannibalization_alert_microsoft:{$customer->id}";
        if (Cache::has($cacheKey)) return;
        Cache::put($cacheKey, true, now()->addHours(168));

        foreach ($customer->users as $user) {
            $user->notify(new CriticalAgentAlert(
                'keyword_cannibalization',
                'Keyword Cannibalization Detected (Microsoft Ads)',
                "{$count} keyword(s) are appearing in multiple ad groups in Microsoft Ads, causing your campaigns to compete against themselves.",
                [
                    'issues'          => array_map(
                        fn($c) => "\"{$c['keyword']}\" found in {$c['ad_group_count']} ad groups",
                        array_slice($cannibalized, 0, 5)
                    ),
                    'action_required' => 'Consolidate duplicate keywords into single ad groups and use negatives to prevent overlap.',
                ]
            ));
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('DetectKeywordCannibalization failed: ' . $exception->getMessage());
    }
}
