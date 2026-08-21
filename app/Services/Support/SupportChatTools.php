<?php

namespace App\Services\Support;

use App\Models\AdSpendCredit;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Reporting\CrossPlatformAnalyticsService;
use Illuminate\Support\Facades\Log;

/**
 * Read-only tools the support assistant may call to answer questions about the
 * customer's own account.
 *
 * THE SECURITY PROPERTY, AND THE WHOLE REASON THIS IS A CLASS RATHER THAN A
 * CLOSURE IN THE CONTROLLER: the customer is bound once, in the constructor,
 * from the server-side session. No tool takes a customer, account or campaign
 * identifier as an argument, and none is declared in the schema handed to the
 * model. The model therefore has no way to express "read a different account" —
 * not because it is asked not to, but because the vocabulary does not exist.
 *
 * That matters because the model's input is attacker-controlled: the customer
 * types it. Any design where a tool accepts an id and the prompt says "only use
 * your own" is one convincing message away from being a cross-tenant read.
 *
 * Everything here is a read. Nothing mutates, nothing spends money, nothing
 * touches an ad platform API. If a tool ever needs to write, it does not belong
 * in this class.
 */
class SupportChatTools
{
    /** Bound to the model's `days` argument so a question cannot ask for an unbounded scan. */
    private const MAX_DAYS = 90;

    private const DEFAULT_DAYS = 30;

    public function __construct(
        private readonly Customer $customer,
        private readonly CrossPlatformAnalyticsService $analytics,
    ) {}

    /**
     * The schema advertised to the model.
     *
     * Note what is absent: no customer_id, no account_id, no campaign_id. The
     * only parameter any tool accepts is a look-back window.
     *
     * @return list<array<string, mixed>>
     */
    public static function declarations(): array
    {
        return [
            [
                'name' => 'get_account_overview',
                'description' => 'Current state of the customer\'s advertising account: how many campaigns exist, '
                    .'how many are actually running, which ad platforms are connected, and whether their ad-spend '
                    .'credit is healthy. Use this first for general "how am I doing" questions.',
                'parameters' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'get_performance_summary',
                'description' => 'Advertising performance totals across every connected platform — spend, impressions, '
                    .'clicks, conversions, CTR, CPC, CPA and ROAS — for a recent period. Use this to answer questions '
                    .'about results, cost, or what could be improved.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'days' => [
                            'type' => 'integer',
                            'description' => 'Look-back window in days, 1 to 90. Defaults to 30.',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'get_platform_breakdown',
                'description' => 'The same performance figures split per ad platform (Google, Facebook, Microsoft, '
                    .'LinkedIn), so platforms can be compared against each other.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'days' => [
                            'type' => 'integer',
                            'description' => 'Look-back window in days, 1 to 90. Defaults to 30.',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'list_campaigns',
                'description' => 'The customer\'s campaigns with their status, daily budget and platforms. Use when '
                    .'the answer needs to name a specific campaign.',
                'parameters' => ['type' => 'object', 'properties' => (object) []],
            ],
        ];
    }

    /**
     * Execute a tool call from the model.
     *
     * Never throws. A tool that fails returns a structured error the model can
     * read and work around, because the alternative — an exception escaping
     * mid-conversation — costs the customer their reply for a question the
     * assistant could still have partly answered.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function handle(string $name, array $args): array
    {
        try {
            return match ($name) {
                'get_account_overview' => $this->accountOverview(),
                'get_performance_summary' => $this->performanceSummary($this->days($args)),
                'get_platform_breakdown' => $this->platformBreakdown($this->days($args)),
                'list_campaigns' => $this->listCampaigns(),
                // An unknown name means the model invented a tool. Say so plainly
                // rather than returning something that looks like data.
                default => ['error' => "Unknown tool: {$name}"],
            };
        } catch (\Throwable $e) {
            report($e);
            Log::error('Support chat tool failed', [
                'tool' => $name,
                'customer_id' => $this->customer->id,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'That information could not be retrieved right now.'];
        }
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function days(array $args): int
    {
        $days = (int) ($args['days'] ?? self::DEFAULT_DAYS);

        // Clamped rather than validated: the model producing 3650 is a reason to
        // read a year of data, not a reason to fail the customer's question.
        return max(1, min(self::MAX_DAYS, $days));
    }

    /**
     * @return array<string, mixed>
     */
    private function accountOverview(): array
    {
        $campaigns = Campaign::where('customer_id', $this->customer->id)->get();
        $credit = AdSpendCredit::where('customer_id', $this->customer->id)->first();

        return [
            'account_name' => $this->customer->name,
            'campaigns_total' => $campaigns->count(),
            'campaigns_live' => $campaigns->filter(fn ($c) => $c->isServing())->count(),
            'campaigns_by_status' => $campaigns->groupBy(fn ($c) => $c->status->value)
                ->map->count()
                ->all(),
            'connected_platforms' => $this->connectedPlatforms(),
            'ad_spend_credit' => $credit ? [
                'balance' => (float) $credit->current_balance,
                'currency' => $credit->currency,
                'status' => $credit->status,
                'payment_status' => $credit->payment_status,
            ] : null,
            'conversion_tracking_verified' => $this->customer->conversion_tracking_verified_at !== null,
        ];
    }

    /**
     * @return list<string>
     */
    private function connectedPlatforms(): array
    {
        return collect([
            'Google Ads' => $this->customer->google_ads_customer_id,
            'Facebook Ads' => $this->customer->facebook_ads_account_id,
            'Microsoft Ads' => $this->customer->microsoft_ads_customer_id,
            'LinkedIn Ads' => $this->customer->linkedin_ads_account_id,
        ])->filter()->keys()->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function performanceSummary(int $days): array
    {
        $summary = $this->analytics->getSummary($this->customer, $days);

        return [
            'period_days' => $days,
            'period' => $summary['period'],
            'totals' => $summary['totals'],
            // Said explicitly so the model reports "no data yet" rather than
            // presenting a row of zeros as a performance result.
            'has_data' => ($summary['totals']['impressions'] ?? 0) > 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function platformBreakdown(int $days): array
    {
        $summary = $this->analytics->getSummary($this->customer, $days);

        $platforms = collect($summary['platforms'])
            ->filter(fn ($metrics) => ($metrics['impressions'] ?? 0) > 0 || ($metrics['cost'] ?? 0) > 0)
            ->all();

        return [
            'period_days' => $days,
            'platforms' => $platforms,
            'has_data' => $platforms !== [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listCampaigns(): array
    {
        $campaigns = Campaign::where('customer_id', $this->customer->id)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get(['id', 'name', 'status', 'primary_status', 'daily_budget', 'platforms', 'created_at']);

        return [
            'count' => $campaigns->count(),
            'campaigns' => $campaigns->map(fn ($c) => [
                'name' => $c->name,
                'status' => $c->status->value,
                // Two different statuses on purpose: ours and the platform's.
                // They drift, and a customer asking why a campaign is not
                // running usually needs the platform's answer, not ours.
                'platform_reports' => $c->primary_status,
                'is_serving' => $c->isServing(),
                'daily_budget' => (float) $c->daily_budget,
                'platforms' => $c->platforms,
                'created' => $c->created_at?->toDateString(),
            ])->all(),
        ];
    }
}
