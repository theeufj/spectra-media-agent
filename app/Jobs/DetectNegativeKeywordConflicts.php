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
 * Weekly job that detects negative keywords blocking active positive keywords
 * within the same campaign — one of the most common SEM audit findings.
 */
class DetectNegativeKeywordConflicts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    public function handle(): void
    {
        Log::info("DetectNegativeKeywordConflicts: Starting weekly conflict scan");

        $googleCustomers = Customer::whereNotNull('google_ads_customer_id')
            ->whereHas('campaigns', fn($q) => $q->whereNotNull('google_ads_campaign_id')->where('status', 'active'))
            ->get();

        foreach ($googleCustomers as $customer) {
            try {
                $this->scanCustomer($customer);
            } catch (\Exception $e) {
                Log::error("DetectNegativeKeywordConflicts: Failed for customer {$customer->id}: " . $e->getMessage());
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
                Log::error("DetectNegativeKeywordConflicts: Failed (Microsoft) for customer {$customer->id}: " . $e->getMessage());
            }
        }

        Log::info("DetectNegativeKeywordConflicts: Scan complete");
    }

    private function scanCustomer(Customer $customer): void
    {
        $customerId = $customer->cleanGoogleCustomerId();

        $service = new class($customer) extends BaseGoogleAdsService {
            public function fetchPositiveKeywords(string $customerId): array
            {
                $this->ensureClient();
                $query = "SELECT campaign.resource_name, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type "
                       . "FROM ad_group_criterion "
                       . "WHERE ad_group_criterion.type = 'KEYWORD' "
                       . "AND ad_group_criterion.status = 'ENABLED' "
                       . "AND campaign.status = 'ENABLED'";

                $results = [];
                foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
                    $criterion = $row->getAdGroupCriterion();
                    $results[] = [
                        'campaign'   => $row->getCampaign()->getResourceName(),
                        'text'       => strtolower(trim($criterion->getKeyword()->getText())),
                        'match_type' => $criterion->getKeyword()->getMatchType(),
                    ];
                }
                return $results;
            }

            public function fetchNegativeKeywords(string $customerId): array
            {
                $this->ensureClient();
                $query = "SELECT campaign.resource_name, campaign_criterion.keyword.text "
                       . "FROM campaign_criterion "
                       . "WHERE campaign_criterion.type = 'KEYWORD' "
                       . "AND campaign_criterion.negative = true "
                       . "AND campaign.status = 'ENABLED'";

                $results = [];
                foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
                    $results[] = [
                        'campaign' => $row->getCampaign()->getResourceName(),
                        'text'     => strtolower(trim($row->getCampaignCriterion()->getKeyword()->getText())),
                    ];
                }
                return $results;
            }
        };

        $positives = $service->fetchPositiveKeywords($customerId);
        $negatives = $service->fetchNegativeKeywords($customerId);

        if (empty($positives) || empty($negatives)) {
            return;
        }

        // Index negatives by campaign for quick lookup
        $negsByCampaign = [];
        foreach ($negatives as $neg) {
            $negsByCampaign[$neg['campaign']][] = $neg['text'];
        }

        $conflicts = [];
        foreach ($positives as $pos) {
            $campaignNegs = $negsByCampaign[$pos['campaign']] ?? [];
            foreach ($campaignNegs as $negText) {
                // Exact match: negative blocks positive if the negative text is contained in keyword text
                if ($pos['text'] === $negText || str_contains($pos['text'], $negText)) {
                    $conflicts[] = [
                        'campaign'        => $pos['campaign'],
                        'positive_keyword' => $pos['text'],
                        'negative_keyword' => $negText,
                    ];
                }
            }
        }

        if (empty($conflicts)) {
            return;
        }

        $conflictCount = count($conflicts);
        Log::warning("DetectNegativeKeywordConflicts: Found {$conflictCount} conflicts for customer {$customer->id}");

        AgentActivity::record(
            'keyword',
            'negative_keyword_conflict',
            "Detected {$conflictCount} negative keyword conflict(s) blocking active keywords",
            $customer->id,
            null,
            ['conflicts' => array_slice($conflicts, 0, 20)]
        );

        $cacheKey = "neg_conflict_alert:{$customer->id}";
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, now()->addHours(4));

        foreach ($customer->users as $user) {
            $user->notify(new CriticalAgentAlert(
                'negative_keyword_conflict',
                'Negative Keyword Conflicts Detected',
                "{$conflictCount} negative keyword(s) are blocking active positive keywords in your campaigns.",
                [
                    'issues'          => array_map(
                        fn($c) => "Negative \"{$c['negative_keyword']}\" blocks \"{$c['positive_keyword']}\"",
                        array_slice($conflicts, 0, 5)
                    ),
                    'action_required' => 'Review your negative keyword lists in Google Ads to remove conflicting terms.',
                ]
            ));
        }
    }

    private function scanMicrosoftCustomer(Customer $customer): void
    {
        $service   = new MicrosoftAdGroupService($customer);
        $positives = [];
        $negsByCampaign = [];

        $activeCampaigns = $customer->campaigns()
            ->whereNotNull('microsoft_ads_campaign_id')
            ->where('status', 'active')
            ->get();

        foreach ($activeCampaigns as $campaign) {
            $campaignId = (string) $campaign->microsoft_ads_campaign_id;
            $adGroups   = $service->getAdGroupsByCampaignId($campaignId);

            foreach ($adGroups as $adGroup) {
                $adGroupId = (string) ($adGroup['Id'] ?? '');
                if (!$adGroupId) continue;

                foreach ($service->getKeywordsByAdGroupId($adGroupId) as $kw) {
                    if (($kw['Status'] ?? '') !== 'Active') continue;
                    $positives[] = [
                        'campaign' => $campaignId,
                        'text'     => strtolower(trim($kw['Text'] ?? '')),
                    ];
                }
            }

            // Fetch campaign-level negatives
            $negResult = $service->getNegativeKeywordsByCampaignIds([(int) $campaignId]);
            foreach ($negResult as $entityNeg) {
                $negs = $entityNeg['NegativeKeywords']['NegativeKeyword'] ?? [];
                if (isset($negs['Text'])) {
                    $negs = [$negs];
                }
                foreach ($negs as $neg) {
                    $negsByCampaign[$campaignId][] = strtolower(trim($neg['Text'] ?? ''));
                }
            }
        }

        if (empty($positives) || empty($negsByCampaign)) return;

        $conflicts = [];
        foreach ($positives as $pos) {
            foreach ($negsByCampaign[$pos['campaign']] ?? [] as $negText) {
                if ($pos['text'] === $negText || str_contains($pos['text'], $negText)) {
                    $conflicts[] = [
                        'campaign'         => $pos['campaign'],
                        'positive_keyword' => $pos['text'],
                        'negative_keyword' => $negText,
                    ];
                }
            }
        }

        if (empty($conflicts)) return;

        $conflictCount = count($conflicts);
        Log::warning("DetectNegativeKeywordConflicts (Microsoft): Found {$conflictCount} conflicts for customer {$customer->id}");

        AgentActivity::record(
            'keyword',
            'negative_keyword_conflict',
            "Detected {$conflictCount} negative keyword conflict(s) blocking active keywords (Microsoft Ads)",
            $customer->id,
            null,
            ['platform' => 'microsoft_ads', 'conflicts' => array_slice($conflicts, 0, 20)]
        );

        $cacheKey = "neg_conflict_alert_microsoft:{$customer->id}";
        if (Cache::has($cacheKey)) return;
        Cache::put($cacheKey, true, now()->addHours(4));

        foreach ($customer->users as $user) {
            $user->notify(new CriticalAgentAlert(
                'negative_keyword_conflict',
                'Negative Keyword Conflicts Detected (Microsoft Ads)',
                "{$conflictCount} negative keyword(s) are blocking active positive keywords in your Microsoft Ads campaigns.",
                [
                    'issues'          => array_map(
                        fn($c) => "Negative \"{$c['negative_keyword']}\" blocks \"{$c['positive_keyword']}\"",
                        array_slice($conflicts, 0, 5)
                    ),
                    'action_required' => 'Review your negative keyword lists in Microsoft Ads to remove conflicting terms.',
                ]
            ));
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('DetectNegativeKeywordConflicts failed: ' . $exception->getMessage());
    }
}
