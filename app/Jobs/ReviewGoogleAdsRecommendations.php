<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\CriticalAgentAlert;
use App\Services\GoogleAds\CommonServices\DismissRecommendation;
use App\Services\GoogleAds\CommonServices\GetGoogleAdsRecommendations;
use Google\Ads\GoogleAds\V22\Enums\RecommendationTypeEnum\RecommendationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs at 4am alongside AutomatedCampaignMaintenance.
 *
 * For every customer with active Google Ads campaigns:
 *
 *  AUTO-DISMISS — recommendations our system already handles. These would
 *  otherwise pile up in the Google Ads UI after every agent run:
 *    - KEYWORD_MATCH_TYPE / USE_BROAD_MATCH_KEYWORD (ExpandBroadMatchKeywords handles this)
 *    - MOVE_UNUSED_BUDGET (our budget agent handles cross-campaign allocation)
 *
 *  WARN — recommendations that suggest an agent may have made a bad decision.
 *  Sends a CriticalAgentAlert email so a human can review:
 *    - CAMPAIGN_BUDGET          → budget agent may have cut spend too aggressively
 *    - RAISE_TARGET_CPA_BID_TOO_LOW → bidding agent set CPA target unrealistically low
 *    - KEYWORD                  → keyword agent added poor-quality keywords
 *    - RESPONSIVE_SEARCH_AD / RESPONSIVE_SEARCH_AD_IMPROVE_AD_STRENGTH
 *                               → creative agent produced weak ad copy
 *    - SHOPPING_FIX_DISAPPROVED_PRODUCTS / SHOPPING_FIX_SUSPENDED_MERCHANT_CENTER_ACCOUNT
 *                               → merchant center issues that need immediate attention
 *
 *  ADVISORY — useful for visibility but not urgent. Logged to AgentActivity only.
 */
class ReviewGoogleAdsRecommendations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 2;
    public $timeout = 300;

    // Recommendations our agents handle automatically — dismiss these from Google's UI
    private const AUTO_DISMISS = [
        RecommendationType::KEYWORD_MATCH_TYPE,
        RecommendationType::USE_BROAD_MATCH_KEYWORD,
        RecommendationType::MOVE_UNUSED_BUDGET,
    ];

    // Recommendations that suggest an agent may have done something harmful
    private const WARN = [
        RecommendationType::CAMPAIGN_BUDGET                                => 'Budget agent may have cut campaign spend too aggressively',
        RecommendationType::RAISE_TARGET_CPA_BID_TOO_LOW                  => 'Bidding agent may have set Target CPA unrealistically low',
        RecommendationType::KEYWORD                                        => 'Keyword agent may have added poor-quality keywords',
        RecommendationType::RESPONSIVE_SEARCH_AD                          => 'Creative agent may have produced weak ad copy',
        RecommendationType::RESPONSIVE_SEARCH_AD_IMPROVE_AD_STRENGTH      => 'Creative agent ad strength is below Google\'s recommendation',
        RecommendationType::SHOPPING_FIX_DISAPPROVED_PRODUCTS             => 'Disapproved products detected — merchant feed may need attention',
        RecommendationType::SHOPPING_FIX_SUSPENDED_MERCHANT_CENTER_ACCOUNT => 'Merchant Center account suspension — requires immediate review',
    ];

    public function handle(): void
    {
        $customers = Customer::whereNotNull('google_ads_customer_id')
            ->whereHas('campaigns', fn($q) => $q->where('status', 'active')->whereNotNull('google_ads_campaign_id'))
            ->with(['campaigns' => fn($q) => $q->where('status', 'active')->whereNotNull('google_ads_campaign_id')])
            ->get();

        Log::info("ReviewGoogleAdsRecommendations: Checking {$customers->count()} customer(s)");

        foreach ($customers as $customer) {
            $this->reviewCustomer($customer);
        }
    }

    private function reviewCustomer(Customer $customer): void
    {
        $customerId  = $customer->cleanGoogleCustomerId();
        $getRecsService = new GetGoogleAdsRecommendations($customer);
        $dismissService = new DismissRecommendation($customer);

        $allRecs     = ($getRecsService)($customerId);

        if (empty($allRecs)) {
            return;
        }

        $toDismiss   = [];
        $warnings    = [];  // [type_int => [campaign_resource, ...]]
        $advisory    = [];

        foreach ($allRecs as $rec) {
            $type = $rec['type'];

            if (in_array($type, self::AUTO_DISMISS)) {
                $toDismiss[] = $rec['resource_name'];
                continue;
            }

            if (isset(self::WARN[$type])) {
                $warnings[$type][] = $rec;
                continue;
            }

            $advisory[] = $rec;
        }

        // Dismiss auto-handled recommendations
        if (!empty($toDismiss)) {
            $dismissed = ($dismissService)($customerId, $toDismiss);
            Log::info("ReviewGoogleAdsRecommendations: Dismissed " . count($toDismiss) . " auto-handled recommendation(s) for customer {$customer->id}");
        }

        // Alert on warnings
        if (!empty($warnings)) {
            $this->sendWarningAlert($customer, $warnings);
        }

        // Log advisory items
        if (!empty($advisory)) {
            $typeNames = array_unique(array_column($advisory, 'type'));
            AgentActivity::record(
                'google_ads_recommendations',
                'advisory_recommendations',
                count($advisory) . ' advisory recommendation(s) from Google Ads for "' . $customer->name . '" — types: ' . implode(', ', $typeNames),
                $customer->id,
                null,
                ['recommendations' => $advisory]
            );
        }

        Log::info("ReviewGoogleAdsRecommendations: Customer {$customer->id} — dismissed: " . count($toDismiss) . ", warnings: " . count($warnings) . ", advisory: " . count($advisory));
    }

    private function sendWarningAlert(Customer $customer, array $warnings): void
    {
        $issues = [];
        foreach ($warnings as $type => $recs) {
            $reason   = self::WARN[$type] ?? 'Unknown recommendation type ' . $type;
            $count    = count($recs);
            $issues[] = "{$reason} ({$count} campaign(s) affected)";
        }

        $admins = User::whereHas('roles', fn($q) => $q->where('name', 'admin'))->get();
        foreach ($admins as $admin) {
            $admin->notify(new CriticalAgentAlert(
                'google_ads_recommendation_warning',
                'Google Ads flagged potential agent issues for ' . $customer->name,
                'Google Ads has raised recommendations that may indicate our agents made suboptimal changes. Please review the following:',
                [
                    'issues'          => $issues,
                    'customer_name'   => $customer->name,
                    'action_required' => 'Review the Google Ads Recommendations tab for ' . $customer->name . ' and verify recent agent actions look correct.',
                ]
            ));
        }

        AgentActivity::record(
            'google_ads_recommendations',
            'agent_warning_flagged',
            'Google Ads flagged ' . count($warnings) . ' potential agent issue(s) for "' . $customer->name . '": ' . implode('; ', $issues),
            $customer->id,
            null,
            ['warnings' => array_map(fn($recs) => count($recs), $warnings)]
        );

        Log::warning("ReviewGoogleAdsRecommendations: Sent warning alert for customer {$customer->id}", [
            'issues' => $issues,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ReviewGoogleAdsRecommendations failed: " . $exception->getMessage());
    }
}
