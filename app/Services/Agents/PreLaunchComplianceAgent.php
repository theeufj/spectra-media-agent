<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Notifications\CriticalAgentAlert;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Runs pre-deployment compliance checks before any execution agent fires.
 *
 * Checks:
 *  1. Ad copy policy violations (restricted terms)
 *  2. Landing page reachability (HTTP 200)
 *  3. Conversion tracking configured
 *  4. Minimum daily budget per platform
 */
class PreLaunchComplianceAgent
{
    private const GLOBAL_RESTRICTED_TERMS = [
        'guarantee', 'guaranteed', 'cure', 'miracle', '#1', 'best price',
        'before and after', 'instant results', 'lose weight fast', 'risk free',
        'make money fast', 'get rich quick', 'no side effects',
    ];

    private const MIN_BUDGETS = [
        'google'    => 8.33,
        'facebook'  => 5.00,
        'microsoft' => 5.00,
        'linkedin'  => 10.00,
    ];

    public function check(Campaign $campaign): array
    {
        $failures = [];

        $this->checkAdCopyPolicy($campaign, $failures);
        $this->checkLandingPage($campaign, $failures);
        $this->checkConversionTracking($campaign, $failures);
        $this->checkMinimumBudget($campaign, $failures);

        $passed = empty($failures);

        if (!$passed) {
            $this->handleFailure($campaign, $failures);
        }

        Log::info("PreLaunchComplianceAgent: Campaign {$campaign->id} " . ($passed ? 'passed' : 'FAILED') . " compliance check", [
            'failures' => $failures,
        ]);

        return ['passed' => $passed, 'failures' => $failures];
    }

    private function checkAdCopyPolicy(Campaign $campaign, array &$failures): void
    {
        $vertical = $campaign->customer?->industry ?? '';
        $verticalTerms = config("verticals.{$vertical}.restricted_terms", []);
        $allRestricted = array_merge(self::GLOBAL_RESTRICTED_TERMS, $verticalTerms);

        // Collect all ad copy text from strategies
        $adTexts = [];
        foreach ($campaign->strategies ?? [] as $strategy) {
            if (!empty($strategy->headlines)) {
                $adTexts = array_merge($adTexts, (array) $strategy->headlines);
            }
            if (!empty($strategy->descriptions)) {
                $adTexts = array_merge($adTexts, (array) $strategy->descriptions);
            }
            if (!empty($strategy->ad_copy)) {
                $adTexts[] = is_array($strategy->ad_copy) ? implode(' ', $strategy->ad_copy) : $strategy->ad_copy;
            }
        }

        $allText = strtolower(implode(' ', $adTexts));

        foreach ($allRestricted as $term) {
            if (str_contains($allText, strtolower($term))) {
                $failures[] = [
                    'type'    => 'policy_violation',
                    'message' => "Ad copy contains restricted term: \"{$term}\"",
                ];
            }
        }
    }

    private function checkLandingPage(Campaign $campaign, array &$failures): void
    {
        $url = $campaign->landing_page_url;

        foreach ($campaign->strategies ?? [] as $strategy) {
            if (!empty($strategy->landing_page_url)) {
                $url = $strategy->landing_page_url;
                break;
            }
        }

        if (!$url) {
            return;
        }

        try {
            $response = Http::timeout(10)->get($url);
            if ($response->status() >= 400) {
                $failures[] = [
                    'type'    => 'landing_page_unreachable',
                    'message' => "Landing page returned HTTP {$response->status()}: {$url}",
                ];
            }
        } catch (\Exception $e) {
            $failures[] = [
                'type'    => 'landing_page_unreachable',
                'message' => "Landing page unreachable: {$url} ({$e->getMessage()})",
            ];
        }
    }

    private function checkConversionTracking(Campaign $campaign, array &$failures): void
    {
        $customer = $campaign->customer;
        if (!$customer) {
            return;
        }

        if ($campaign->google_ads_campaign_id && !$customer->conversion_action_id) {
            $failures[] = [
                'type'    => 'missing_conversion_tracking',
                'message' => 'Google Ads campaign has no conversion action configured',
            ];
        }

        if ($campaign->facebook_ads_campaign_id && !$customer->facebook_pixel_id) {
            $failures[] = [
                'type'    => 'missing_conversion_tracking',
                'message' => 'Facebook campaign has no pixel configured',
            ];
        }
    }

    private function checkMinimumBudget(Campaign $campaign, array &$failures): void
    {
        $dailyBudget = (float) ($campaign->daily_budget ?? 0);
        if ($dailyBudget <= 0) {
            return;
        }

        $platformCount = collect([
            'google'    => $campaign->google_ads_campaign_id,
            'facebook'  => $campaign->facebook_ads_campaign_id,
            'microsoft' => $campaign->microsoft_ads_campaign_id,
            'linkedin'  => $campaign->linkedin_campaign_id,
        ])->filter()->count();

        if ($platformCount === 0) {
            return;
        }

        $budgetPerPlatform = $dailyBudget / max($platformCount, 1);

        foreach (self::MIN_BUDGETS as $platform => $minimum) {
            $hasField = match ($platform) {
                'google'    => $campaign->google_ads_campaign_id,
                'facebook'  => $campaign->facebook_ads_campaign_id,
                'microsoft' => $campaign->microsoft_ads_campaign_id,
                'linkedin'  => $campaign->linkedin_campaign_id,
            };

            if ($hasField && $budgetPerPlatform < $minimum) {
                $failures[] = [
                    'type'    => 'budget_below_minimum',
                    'message' => sprintf(
                        '%s daily budget $%.2f is below minimum $%.2f',
                        ucfirst($platform),
                        $budgetPerPlatform,
                        $minimum
                    ),
                ];
            }
        }
    }

    private function handleFailure(Campaign $campaign, array $failures): void
    {
        AgentActivity::record(
            'compliance',
            'compliance_check_failed',
            "Campaign \"{$campaign->name}\" failed pre-launch compliance (" . count($failures) . " issue(s))",
            $campaign->customer_id,
            $campaign->id,
            ['failures' => $failures],
            'failed'
        );

        if ($campaign->customer && $campaign->customer->users) {
            foreach ($campaign->customer->users as $user) {
                $user->notify(new CriticalAgentAlert(
                    'compliance_failure',
                    'Campaign Compliance Check Failed',
                    "Your campaign \"{$campaign->name}\" cannot be deployed due to compliance issues.",
                    [
                        'campaign_id'   => $campaign->id,
                        'campaign_name' => $campaign->name,
                        'issues'        => array_column($failures, 'message'),
                        'action_required' => 'Please review and fix the issues listed before deploying.',
                    ]
                ));
            }
        }
    }
}
