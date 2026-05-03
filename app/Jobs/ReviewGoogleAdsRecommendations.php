<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\CriticalAgentAlert;
use App\Services\GoogleAds\CommonServices\ApplyRecommendation;
use App\Services\GoogleAds\CommonServices\DismissRecommendation;
use App\Services\GoogleAds\CommonServices\GetGoogleAdsRecommendations;
use App\Services\NotificationService;
use App\Services\Agents\CreativeIntelligenceAgent;
use App\Services\GeminiService;
use Google\Ads\GoogleAds\V22\Enums\RecommendationTypeEnum\RecommendationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs at 04:30 daily. For every customer with active Google Ads campaigns,
 * fetches all open recommendations and takes the appropriate action:
 *
 *  AUTO-APPLY  — bidding/CPA adjustments Google is confident about.
 *                Applied using Google's own suggested values.
 *
 *  AUTO-FIX    — ad copy issues routed to CreativeIntelligenceAgent,
 *                which knows brand guidelines and performance history.
 *
 *  AUTO-DISMISS — things our agents already handle (broad match expansion,
 *                 budget reallocation). Dismissed so they don't clutter the UI.
 *
 *  NOTIFY CLIENT — budget increases. It's the client's money; they decide.
 *                  Sends an in-app notification to the customer's user.
 *
 *  NOTIFY ADMIN — account-level problems we genuinely cannot auto-fix:
 *                 Merchant Center suspensions, disapproved products.
 *                 These require a human to resolve policy or content issues.
 */
class ReviewGoogleAdsRecommendations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 2;
    public $timeout = 300;

    private const AUTO_APPLY = [
        RecommendationType::RAISE_TARGET_CPA_BID_TOO_LOW,
        RecommendationType::RAISE_TARGET_CPA,
        RecommendationType::OPTIMIZE_AD_ROTATION,
    ];

    private const AUTO_FIX_CREATIVE = [
        RecommendationType::RESPONSIVE_SEARCH_AD,
        RecommendationType::RESPONSIVE_SEARCH_AD_IMPROVE_AD_STRENGTH,
        RecommendationType::RESPONSIVE_SEARCH_AD_ASSET,
    ];

    private const AUTO_DISMISS = [
        RecommendationType::KEYWORD_MATCH_TYPE,
        RecommendationType::USE_BROAD_MATCH_KEYWORD,
        RecommendationType::MOVE_UNUSED_BUDGET,
    ];

    private const NOTIFY_CLIENT_BUDGET = [
        RecommendationType::CAMPAIGN_BUDGET,
        RecommendationType::FORECASTING_CAMPAIGN_BUDGET,
        RecommendationType::MARGINAL_ROI_CAMPAIGN_BUDGET,
    ];

    private const NEEDS_HUMAN = [
        RecommendationType::SHOPPING_FIX_SUSPENDED_MERCHANT_CENTER_ACCOUNT => 'Merchant Center account suspension — requires human review to resolve policy or payment issue',
        RecommendationType::SHOPPING_FIX_DISAPPROVED_PRODUCTS               => 'Disapproved products — product feed content needs human review',
    ];

    public function __construct(
        private readonly NotificationService $notifications = new NotificationService()
    ) {}

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
        $customerId     = $customer->cleanGoogleCustomerId();
        $getRecs        = new GetGoogleAdsRecommendations($customer);
        $apply          = new ApplyRecommendation($customer);
        $dismiss        = new DismissRecommendation($customer);

        $allRecs = ($getRecs)($customerId);

        if (empty($allRecs)) {
            return;
        }

        $toApply        = [];
        $toDismiss      = [];
        $creativeCampaigns = [];  // campaign_resource => Campaign model
        $humanNeeded    = [];

        foreach ($allRecs as $rec) {
            $type = $rec['type'];

            if (in_array($type, self::AUTO_APPLY)) {
                $toApply[] = $rec['resource_name'];
                continue;
            }

            if (in_array($type, self::AUTO_FIX_CREATIVE)) {
                $creativeCampaigns[$rec['campaign_resource']] = true;
                $toDismiss[] = $rec['resource_name'];
                continue;
            }

            if (in_array($type, self::AUTO_DISMISS)) {
                $toDismiss[] = $rec['resource_name'];
                continue;
            }

            if (in_array($type, self::NOTIFY_CLIENT_BUDGET)) {
                $this->notifyClientBudget($customer, $rec);
                continue;
            }

            if (isset(self::NEEDS_HUMAN[$type])) {
                $humanNeeded[$type] = self::NEEDS_HUMAN[$type];
                continue;
            }

            // Everything else: log for visibility and dismiss so the UI stays clean
            $toDismiss[] = $rec['resource_name'];
            AgentActivity::record(
                'google_ads_recommendations',
                'recommendation_dismissed',
                "Dismissed unhandled recommendation type {$type} for customer \"{$customer->name}\"",
                $customer->id
            );
        }

        // Auto-apply bidding/CPA recommendations
        if (!empty($toApply)) {
            $ok = ($apply)($customerId, $toApply);
            if ($ok) {
                AgentActivity::record(
                    'google_ads_recommendations',
                    'recommendations_applied',
                    'Auto-applied ' . count($toApply) . ' Google Ads recommendation(s) for "' . $customer->name . '"',
                    $customer->id,
                    null,
                    ['applied' => $toApply]
                );
            }
        }

        // Dismiss auto-handled and creative ones (creative agent will fix the actual issue)
        if (!empty($toDismiss)) {
            ($dismiss)($customerId, $toDismiss);
        }

        // Trigger creative agent for campaigns with weak ad copy
        if (!empty($creativeCampaigns)) {
            $this->triggerCreativeFix($customer, array_keys($creativeCampaigns));
        }

        // Notify admin only for things we truly can't fix
        if (!empty($humanNeeded)) {
            $this->notifyAdminHumanRequired($customer, $humanNeeded);
        }

        Log::info("ReviewGoogleAdsRecommendations: Customer {$customer->id} — applied: " . count($toApply) . ", dismissed: " . count($toDismiss) . ", creative-fix: " . count($creativeCampaigns) . ", human-needed: " . count($humanNeeded));
    }

    private function notifyClientBudget(Customer $customer, array $rec): void
    {
        $user = $customer->user;
        if (!$user) {
            return;
        }

        $this->notifications->notify(
            $user,
            'google_ads.budget_recommendation',
            'Budget increase recommended for your campaign',
            'Google Ads is recommending a budget increase for one of your campaigns to capture more conversions. Please review and approve or decline in your campaign settings.',
            route('campaigns.index'),
            'Review Budget',
            $customer,
            ['recommendation_resource' => $rec['resource_name'], 'campaign_resource' => $rec['campaign_resource']]
        );

        Log::info("ReviewGoogleAdsRecommendations: Notified customer {$customer->id} of budget recommendation");
    }

    private function triggerCreativeFix(Customer $customer, array $campaignResources): void
    {
        $campaigns = $customer->campaigns()
            ->whereIn('google_ads_campaign_id', $campaignResources)
            ->get();

        if ($campaigns->isEmpty()) {
            return;
        }

        $agent = new CreativeIntelligenceAgent(app(GeminiService::class));
        $fixed = 0;

        foreach ($campaigns as $campaign) {
            try {
                $agent->analyze($campaign);
                $fixed++;
            } catch (\Throwable $e) {
                Log::error("ReviewGoogleAdsRecommendations: Creative fix failed for campaign {$campaign->id}: " . $e->getMessage());
            }
        }

        AgentActivity::record(
            'google_ads_recommendations',
            'creative_fix_triggered',
            'Ran creative agent on ' . $fixed . ' campaign(s) with weak ad copy for "' . $customer->name . '"',
            $customer->id
        );
    }

    private function notifyAdminHumanRequired(Customer $customer, array $issues): void
    {
        $admins = User::whereHas('roles', fn($q) => $q->where('name', 'admin'))->get();

        foreach ($admins as $admin) {
            $admin->notify(new CriticalAgentAlert(
                'google_ads_human_required',
                $customer->name . ': Google Ads issue requires human review',
                'The following issue(s) in "' . $customer->name . '" cannot be fixed automatically and need a human to resolve:',
                [
                    'issues'          => array_values($issues),
                    'customer_name'   => $customer->name,
                    'action_required' => 'Log into the Google Ads account for ' . $customer->name . ' and resolve the flagged issue(s).',
                ]
            ));
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ReviewGoogleAdsRecommendations failed: " . $exception->getMessage());
    }
}
