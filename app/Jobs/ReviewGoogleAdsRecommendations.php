<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\CriticalAgentAlert;
use App\Services\Agents\QualityScoreImprovementAgent;
use App\Services\GoogleAds\CommonServices\ApplyRecommendation;
use App\Services\GoogleAds\CommonServices\DismissRecommendation;
use App\Services\GoogleAds\CommonServices\GetGoogleAdsRecommendations;
use App\Services\NotificationService;
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
 *  AUTO-FIX    — ad copy issues routed to the reviewed RSA repair loop.
 *                Recommendations remain open until strength is verified.
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
    use \App\Jobs\Concerns\RecordsAgentRun, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 300;

    private int $repairErrors = 0;

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
        RecommendationType::SHOPPING_FIX_DISAPPROVED_PRODUCTS => 'Disapproved products — product feed content needs human review',
    ];

    public function __construct(
        private readonly NotificationService $notifications = new NotificationService
    ) {}

    public function handle(): void
    {
        $runStart = $this->startRun();

        $this->repairErrors = 0;
        $customers = Customer::whereNotNull('google_ads_customer_id')
            ->where('service_type', '!=', 'setup_only')
            ->where(fn ($q) => $q->whereNull('google_ads_link_status')->orWhere('google_ads_link_status', '!=', 'revoked'))
            ->whereHas('campaigns', fn ($q) => $q->where('status', 'active')->whereNotNull('google_ads_campaign_id'))
            ->with(['campaigns' => fn ($q) => $q->where('status', 'active')->whereNotNull('google_ads_campaign_id')])
            ->get();

        Log::info("ReviewGoogleAdsRecommendations: Checking {$customers->count()} customer(s)");

        $actions = 0;
        $errors = 0;

        foreach ($customers as $customer) {
            try {
                $actions += $this->reviewCustomer($customer);
            } catch (\Throwable $e) {
                report($e);
                $errors++;
                Log::error("ReviewGoogleAdsRecommendations: Failed for customer {$customer->id}: ".$e->getMessage());
            }
        }

        $this->finishRun($runStart, actions: $actions, errors: $errors + $this->repairErrors, scope: $customers->count().' customers');
    }

    private function reviewCustomer(Customer $customer): int
    {
        $customerId = $customer->cleanGoogleCustomerId();
        $getRecs = app(GetGoogleAdsRecommendations::class, ['customer' => $customer]);
        $apply = app(ApplyRecommendation::class, ['customer' => $customer]);
        $dismiss = app(DismissRecommendation::class, ['customer' => $customer]);

        $allRecs = ($getRecs)($customerId);

        if (empty($allRecs)) {
            return 0;
        }

        $toApply = [];
        $toDismiss = [];
        $creativeCampaigns = [];  // campaign_resource => Campaign model
        $humanNeeded = [];

        foreach ($allRecs as $rec) {
            $type = $rec['type'];

            if (in_array($type, self::AUTO_APPLY)) {
                $toApply[] = $rec['resource_name'];

                continue;
            }

            if (in_array($type, self::AUTO_FIX_CREATIVE)) {
                $creativeCampaigns[$rec['campaign_resource']][] = $rec;

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
        if (! empty($toApply)) {
            $ok = ($apply)($customerId, $toApply);
            if ($ok) {
                AgentActivity::record(
                    'google_ads_recommendations',
                    'recommendations_applied',
                    'Auto-applied '.count($toApply).' Google Ads recommendation(s) for "'.$customer->name.'"',
                    $customer->id,
                    null,
                    ['applied' => $toApply]
                );
            }
        }

        // Read and repair independently of performance history. A submitted update
        // is not proof of improvement, so keep its recommendation visible.
        $repairActions = 0;
        if (! empty($creativeCampaigns)) {
            $repair = $this->triggerCreativeFix($customer, $creativeCampaigns);
            $repairActions = $repair['actions'];
            $toDismiss = array_merge($toDismiss, $repair['dismiss']);
        }
        $dismissed = 0;
        if (! empty($toDismiss) && ($dismiss)($customerId, $toDismiss)) {
            $dismissed = count($toDismiss);
        }

        // Notify admin only for things we truly can't fix
        if (! empty($humanNeeded)) {
            $this->notifyAdminHumanRequired($customer, $humanNeeded);
        }

        Log::info("ReviewGoogleAdsRecommendations: Customer {$customer->id} — applied: ".count($toApply).', dismissed: '.count($toDismiss).', creative-fix: '.count($creativeCampaigns).', human-needed: '.count($humanNeeded));

        return count($toApply) + $dismissed + $repairActions;
    }

    private function notifyClientBudget(Customer $customer, array $rec): void
    {
        $user = $customer->users()->wherePivot('role', 'owner')->first()
            ?? $customer->users()->first();
        if (! $user) {
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

    private function triggerCreativeFix(Customer $customer, array $campaignRecommendations): array
    {
        $result = ['dismiss' => [], 'actions' => 0];
        $agent = app(QualityScoreImprovementAgent::class);
        foreach ($campaignRecommendations as $resource => $recommendations) {
            $id = basename($resource);
            $campaign = $customer->campaigns()->where('status', 'active')
                ->where(fn ($q) => $q->where('google_ads_campaign_id', $resource)->orWhere('google_ads_campaign_id', $id))->first();
            if (! $campaign) {
                continue;
            }
            try {
                $repair = $agent->checkAdStrength($campaign);
                $result['actions'] += count($repair['actions'] ?? []);
                $this->repairErrors += count($repair['errors'] ?? []);
                if (($repair['verified'] ?? false) === true && ($repair['checked'] ?? false) === true) {
                    // Other RSA recommendations may concern missing assets, which
                    // a healthy strength rating alone cannot prove were repaired.
                    foreach ($recommendations as $recommendation) {
                        if ($recommendation['type'] === RecommendationType::RESPONSIVE_SEARCH_AD_IMPROVE_AD_STRENGTH) {
                            $result['dismiss'][] = $recommendation['resource_name'];
                        }
                    }
                }
                AgentActivity::record('google_ads_recommendations', 'ad_strength_recommendation_checked',
                    ($repair['verified'] ?? false) ? 'Google confirmed healthy RSA strength.' : 'RSA strength recommendation remains unresolved.',
                    $customer->id, $campaign->id, $repair, ($repair['verified'] ?? false) ? 'completed' : 'pending');
            } catch (\Throwable $e) {
                report($e);
                $this->repairErrors++;
                AgentActivity::record('google_ads_recommendations', 'ad_strength_recommendation_failed', 'Could not verify or repair the RSA recommendation.',
                    $customer->id, $campaign->id, ['error' => $e->getMessage()], 'needs_review');
            }
        }

        return $result;
    }

    private function notifyAdminHumanRequired(Customer $customer, array $issues): void
    {
        CriticalAgentAlert::deliver(
            'google_ads_human_required',
            $customer->name.': Google Ads issue requires human review',
            'The following issue(s) in "'.$customer->name.'" cannot be fixed automatically and need a human to resolve:',
            [
                'issues' => array_values($issues),
                'customer_name' => $customer->name,
                'action_required' => 'Log into the Google Ads account for '.$customer->name.' and resolve the flagged issue(s).',
            ],
            CriticalAgentAlert::RECIPIENTS_ADMINS,
            $customer
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ReviewGoogleAdsRecommendations failed: '.$exception->getMessage());
        $this->recordRunFailure($exception);
    }
}
