<?php

namespace App\Services\Analytics;

use App\Enums\ProductFeature;
use App\Models\Campaign;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Are accounts getting to value, and which parts of the product do they touch?"
 *
 * Everything here is cohort-scoped: of the accounts CREATED in the window, how
 * many reached step N — not how many step-N events happened during the window.
 * The second framing is the tempting one and it lies: a burst of deployments by
 * accounts that signed up last year reads as this month's activation improving.
 *
 * Sandbox accounts (Customer::real()) and soft-deleted ones are excluded
 * throughout. A demo account walks the whole funnel by construction, so leaving
 * them in inflates every conversion rate on the page.
 */
class AdoptionMetrics
{
    private const CACHE_VERSION = 1;

    private const FRESH = 900;

    private const STALE = 3600;

    public function __construct(private readonly UsagePeriod $period) {}

    private function cached(string $section, \Closure $callback): mixed
    {
        // Flat versioned key: Cache::tags() is unavailable on the database
        // store this app is likely running. Bumping CACHE_VERSION in the same
        // commit as a query change orphans every stale entry, so a deploy never
        // needs a cache:clear.
        $key = sprintf('admin:usage:v%d:%s:%s', self::CACHE_VERSION, $section, $this->period->cacheSuffix());

        // flexible(), not remember(): admin.dashboard is the post-2FA redirect
        // target, so a cold miss must not park a human in front of a spinner.
        return Cache::flexible($key, [self::FRESH, self::STALE], $callback);
    }

    /**
     * Activation funnel over the people who signed up in this window.
     *
     * ANCHORED ON USERS, ALL THE WAY DOWN. An earlier version counted signups
     * from `users` and every later step from `customers`, which is not a funnel
     * at all: the two populations are unrelated (a user can own several
     * accounts, an account can have several users), so "percent of signups"
     * happily exceeded 100%. Every step here is a strictly narrower predicate
     * over the SAME set of users, so the steps are nested subsets by
     * construction and the percentages mean what they appear to mean.
     *
     * One statement. Each step is a correlated EXISTS that short-circuits on
     * its first matching row and hits a covering index, wrapped in
     * COUNT(*) FILTER — the alternative (LEFT JOIN … GROUP BY) materialises
     * every campaign and strategy row just to throw them away.
     *
     * Note that deployment does not strictly imply strategy generation: a
     * campaign can carry a deployed strategy without
     * strategy_generation_completed_at ever being set. That is a data anomaly
     * rather than a normal path, and it is left visible (as a step-to-step rate
     * above 100%) rather than papered over.
     *
     * @return list<array{key: string, label: string, count: int, pct_of_start: float, pct_of_previous: ?float}>
     */
    public function funnel(): array
    {
        return $this->cached('funnel', function () {
            $serving = Campaign::SERVING_PRIMARY_STATUSES;
            $placeholders = implode(',', array_fill(0, count($serving), '?'));

            // Every step walks user → customer_user → customers, excluding
            // sandbox and deleted accounts at that hop, so those exclusions
            // apply uniformly to all five steps.
            $reaches = fn (string $extra) => "EXISTS (
                            SELECT 1
                            FROM customer_user cu
                            JOIN customers c ON c.id = cu.customer_id
                            {$extra}
                            WHERE cu.user_id = u.id
                              AND c.deleted_at IS NULL
                              AND c.is_sandbox = false
                        )";

            $row = DB::selectOne(
                <<<SQL
                SELECT
                    COUNT(*)                                    AS signed_up,
                    COUNT(*) FILTER (WHERE has_account)         AS created_account,
                    COUNT(*) FILTER (WHERE has_campaign)        AS created_campaign,
                    COUNT(*) FILTER (WHERE has_strategy)        AS generated_strategy,
                    COUNT(*) FILTER (WHERE has_deploy)          AS deployed,
                    COUNT(*) FILTER (WHERE has_serving)         AS live
                FROM (
                    SELECT
                        {$reaches('')} AS has_account,
                        {$reaches('JOIN campaigns ca ON ca.customer_id = c.id')} AS has_campaign,
                        {$reaches('JOIN campaigns ca ON ca.customer_id = c.id
                                     AND ca.strategy_generation_completed_at IS NOT NULL')} AS has_strategy,
                        {$reaches("JOIN campaigns ca ON ca.customer_id = c.id
                                   JOIN strategies s ON s.campaign_id = ca.id
                                     AND s.deployment_status IN ('deployed', 'verified')")} AS has_deploy,
                        {$reaches("JOIN campaigns ca ON ca.customer_id = c.id
                                     AND ca.primary_status IN ({$placeholders})")} AS has_serving
                    FROM users u
                    WHERE u.created_at >= ?
                      AND u.created_at <= ?
                ) t
                SQL,
                [...$serving, $this->period->since, $this->period->until],
            );

            $steps = [
                ['key' => 'signed_up', 'label' => 'Signed up', 'count' => (int) $row->signed_up],
                ['key' => 'created_account', 'label' => 'Reached an account', 'count' => (int) $row->created_account],
                ['key' => 'created_campaign', 'label' => 'Created a campaign', 'count' => (int) $row->created_campaign],
                ['key' => 'generated_strategy', 'label' => 'Generated a strategy', 'count' => (int) $row->generated_strategy],
                ['key' => 'deployed', 'label' => 'Deployed to a platform', 'count' => (int) $row->deployed],
                ['key' => 'live', 'label' => 'Live on a platform', 'count' => (int) $row->live],
            ];

            $start = $steps[0]['count'];
            $previous = null;

            foreach ($steps as $i => $step) {
                $steps[$i]['pct_of_start'] = $start > 0 ? round($step['count'] / $start * 100, 1) : 0.0;
                // The step-to-step rate is the one that names WHERE people are
                // lost. Percent-of-top alone hides a 90% drop at step 4 behind
                // a small absolute number.
                $steps[$i]['pct_of_previous'] = $previous > 0 ? round($step['count'] / $previous * 100, 1) : null;
                $previous = $step['count'];
            }

            return $steps;
        });
    }

    /**
     * Campaigns where Spectra's own `status` disagrees with what the platform
     * reports in `primary_status`.
     *
     * These two drift by design (see CampaignStatus), and conflating them is
     * what caused BILL-8 — a campaign billed as active while the platform had
     * stopped serving it. Surfacing the count turns a latent billing leak into
     * a number somebody watches. Not scoped to the period: a stale campaign
     * that drifted eight months ago is still wrong today.
     *
     * @return array{believed_live_but_not: int, believed_stopped_but_live: int, unchecked: int}
     */
    public function statusDrift(): array
    {
        return $this->cached('status_drift', function () {
            $serving = Campaign::SERVING_PRIMARY_STATUSES;

            $base = fn () => DB::table('campaigns')
                ->join('customers', 'customers.id', '=', 'campaigns.customer_id')
                ->whereNull('customers.deleted_at')
                ->where('customers.is_sandbox', false);

            return [
                // We think it is running and are billing for it; the platform says otherwise.
                'believed_live_but_not' => (int) $base()
                    ->where('campaigns.status', 'active')
                    ->whereNotNull('campaigns.primary_status')
                    ->whereNotIn('campaigns.primary_status', $serving)
                    ->count(),
                // Spending money we are not tracking.
                'believed_stopped_but_live' => (int) $base()
                    ->whereIn('campaigns.status', ['paused', 'ended', 'completed', 'draft'])
                    ->whereIn('campaigns.primary_status', $serving)
                    ->count(),
                // Never reconciled at all — neither statement can be trusted.
                'unchecked' => (int) $base()
                    ->whereNull('campaigns.primary_status')
                    ->where('campaigns.status', 'active')
                    ->count(),
            ];
        });
    }

    /**
     * Median days between funnel steps.
     *
     * Medians, not means: one account that signed up last year and activated
     * yesterday drags a mean into uselessness, and those accounts exist.
     *
     * @return list<array{key: string, label: string, median_days: ?float, sample: int}>
     */
    public function timeToValue(): array
    {
        return $this->cached('time_to_value', function () {
            $rows = DB::selectOne(
                <<<'SQL'
                SELECT
                    percentile_cont(0.5) WITHIN GROUP (
                        ORDER BY EXTRACT(EPOCH FROM (first_campaign_at - t.created_at)) / 86400
                    ) FILTER (WHERE first_campaign_at IS NOT NULL)   AS to_campaign,
                    COUNT(*) FILTER (WHERE first_campaign_at IS NOT NULL)  AS to_campaign_n,

                    percentile_cont(0.5) WITHIN GROUP (
                        ORDER BY EXTRACT(EPOCH FROM (first_strategy_at - first_campaign_at)) / 86400
                    ) FILTER (WHERE first_strategy_at IS NOT NULL)   AS to_strategy,
                    COUNT(*) FILTER (WHERE first_strategy_at IS NOT NULL)  AS to_strategy_n,

                    percentile_cont(0.5) WITHIN GROUP (
                        ORDER BY EXTRACT(EPOCH FROM (first_deploy_at - first_campaign_at)) / 86400
                    ) FILTER (WHERE first_deploy_at IS NOT NULL)     AS to_deploy,
                    COUNT(*) FILTER (WHERE first_deploy_at IS NOT NULL)    AS to_deploy_n
                FROM (
                    SELECT
                        c.id,
                        c.created_at,
                        (SELECT MIN(ca.created_at) FROM campaigns ca WHERE ca.customer_id = c.id)
                            AS first_campaign_at,
                        (SELECT MIN(ca.strategy_generation_completed_at) FROM campaigns ca
                          WHERE ca.customer_id = c.id) AS first_strategy_at,
                        (SELECT MIN(s.deployed_at) FROM strategies s
                          JOIN campaigns ca ON ca.id = s.campaign_id
                          WHERE ca.customer_id = c.id AND s.deployed_at IS NOT NULL)
                            AS first_deploy_at
                    FROM customers c
                    WHERE c.deleted_at IS NULL
                      AND c.is_sandbox = false
                      AND c.created_at >= ?
                      AND c.created_at <= ?
                ) t
                SQL,
                [$this->period->since, $this->period->until],
            );

            return [
                [
                    'key' => 'to_campaign',
                    'label' => 'Account → first campaign',
                    'median_days' => $rows->to_campaign !== null ? round((float) $rows->to_campaign, 1) : null,
                    'sample' => (int) $rows->to_campaign_n,
                ],
                [
                    'key' => 'to_strategy',
                    'label' => 'Campaign → strategy generated',
                    'median_days' => $rows->to_strategy !== null ? round((float) $rows->to_strategy, 1) : null,
                    'sample' => (int) $rows->to_strategy_n,
                ],
                [
                    'key' => 'to_deploy',
                    'label' => 'Campaign → deployed',
                    'median_days' => $rows->to_deploy !== null ? round((float) $rows->to_deploy, 1) : null,
                    'sample' => (int) $rows->to_deploy_n,
                ],
            ];
        });
    }

    /**
     * How each table proves a feature was used.
     *
     * Only the derivable half lives here — the read-only features
     * (ProductFeature::derivable() is the split) have no row to count and are
     * reported from feature_usage_daily once Phase 4 instruments them.
     *
     * @return array<string, array{table: string, column: string, joins_campaigns?: bool}>
     */
    private function derivableSources(): array
    {
        return [
            ProductFeature::Seo->value => ['table' => 'seo_audits', 'column' => 'created_at'],
            ProductFeature::Cro->value => ['table' => 'landing_page_audits', 'column' => 'audited_at'],
            ProductFeature::Creatives->value => ['table' => 'creative_usages', 'column' => 'updated_at'],
            ProductFeature::ProductFeeds->value => ['table' => 'product_feeds', 'column' => 'created_at'],
            ProductFeature::Proposals->value => ['table' => 'proposals', 'column' => 'created_at'],
            ProductFeature::KnowledgeBase->value => ['table' => 'knowledge_bases', 'column' => 'created_at'],
            ProductFeature::Personas->value => ['table' => 'personas', 'column' => 'created_at'],
            ProductFeature::Brand->value => ['table' => 'brand_guidelines', 'column' => 'created_at'],
            ProductFeature::Team->value => ['table' => 'invitations', 'column' => 'created_at'],
            ProductFeature::AbTests->value => ['table' => 'ab_tests', 'column' => 'created_at', 'joins_campaigns' => true],
            ProductFeature::Recommendations->value => ['table' => 'recommendations', 'column' => 'created_at', 'joins_campaigns' => true],
        ];
    }

    /**
     * Share of active accounts that touched each feature in the window.
     *
     * Denominator is accounts with ANY activity in the window, not all accounts
     * ever — dividing by a pile of dormant 2025 signups makes every feature look
     * unused and makes the chart useless for comparing features against each other.
     *
     * @return array{denominator: int, features: list<array{key: string, label: string, accounts: int, pct: float, derivable: bool}>, unattributed_proposals: int}
     */
    public function featureBreadth(): array
    {
        return $this->cached('feature_breadth', function () {
            $denominator = $this->activeAccountCount();
            $features = [];

            foreach ($this->derivableSources() as $key => $source) {
                $query = DB::table($source['table'].' as f');

                if ($source['joins_campaigns'] ?? false) {
                    // ab_tests and recommendations are campaign-scoped only;
                    // the account they belong to is one join away.
                    $query->join('campaigns as ca', 'ca.id', '=', 'f.campaign_id')
                        ->join('customers as c', 'c.id', '=', 'ca.customer_id');
                } else {
                    $query->join('customers as c', 'c.id', '=', 'f.customer_id');
                }

                $accounts = (int) $query
                    ->whereNull('c.deleted_at')
                    ->where('c.is_sandbox', false)
                    ->whereBetween('f.'.$source['column'], [$this->period->since, $this->period->until])
                    ->distinct()
                    ->count('c.id');

                $feature = ProductFeature::from($key);

                $features[] = [
                    'key' => $key,
                    'label' => $feature->label(),
                    'accounts' => $accounts,
                    'pct' => $denominator > 0 ? round($accounts / $denominator * 100, 1) : 0.0,
                    'derivable' => true,
                ];
            }

            usort($features, fn ($a, $b) => $b['accounts'] <=> $a['accounts']);

            return [
                'denominator' => $denominator,
                'features' => $features,
                // proposals.customer_id is nullable, so some rows belong to a
                // user but to no account. Reported rather than silently dropped:
                // a number that quietly excludes rows is worse than a smaller
                // number with a footnote.
                'unattributed_proposals' => (int) DB::table('proposals')
                    ->whereNull('customer_id')
                    ->whereBetween('created_at', [$this->period->since, $this->period->until])
                    ->count(),
            ];
        });
    }

    /**
     * How many features each account touched — the actual breadth question.
     *
     * "60% of accounts use SEO" and "60% of accounts use exactly one feature"
     * describe very different products, and only this histogram distinguishes
     * them.
     *
     * @return list<array{features: int, accounts: int}>
     */
    public function breadthHistogram(): array
    {
        return $this->cached('breadth_histogram', function () {
            $sources = $this->derivableSources();
            $unions = [];
            $bindings = [];

            foreach ($sources as $source) {
                if ($source['joins_campaigns'] ?? false) {
                    $unions[] = "SELECT DISTINCT ca.customer_id AS customer_id
                                 FROM {$source['table']} f
                                 JOIN campaigns ca ON ca.id = f.campaign_id
                                 WHERE f.{$source['column']} BETWEEN ? AND ?
                                   AND ca.customer_id IS NOT NULL";
                } else {
                    $unions[] = "SELECT DISTINCT f.customer_id AS customer_id
                                 FROM {$source['table']} f
                                 WHERE f.{$source['column']} BETWEEN ? AND ?
                                   AND f.customer_id IS NOT NULL";
                }

                $bindings[] = $this->period->since;
                $bindings[] = $this->period->until;
            }

            // UNION ALL, not UNION: each arm is already DISTINCT within its own
            // feature, and one row per (account, feature) is exactly what makes
            // the outer COUNT a feature count.
            $union = implode("\nUNION ALL\n", $unions);

            $rows = DB::select(
                <<<SQL
                SELECT feature_count AS features, COUNT(*) AS accounts
                FROM (
                    SELECT u.customer_id, COUNT(*) AS feature_count
                    FROM ({$union}) u
                    JOIN customers c ON c.id = u.customer_id
                    WHERE c.deleted_at IS NULL AND c.is_sandbox = false
                    GROUP BY u.customer_id
                ) counts
                GROUP BY feature_count
                ORDER BY feature_count
                SQL,
                $bindings,
            );

            $used = array_map(fn ($r) => [
                'features' => (int) $r->features,
                'accounts' => (int) $r->accounts,
            ], $rows);

            // Accounts that touched nothing are the most important bar on this
            // chart and appear in none of the union arms, so they are added here.
            $touchedAny = array_sum(array_column($used, 'accounts'));
            $zero = max(0, $this->activeAccountCount() - $touchedAny);

            return [['features' => 0, 'accounts' => $zero], ...$used];
        });
    }

    /**
     * Accounts with any sign of life in the window.
     *
     * Campaign writes only, for now. This is deliberately narrow and it
     * OVER-reports dormancy: an account whose owner logs in daily to read
     * dashboards and changes nothing is invisible here. That is precisely the
     * blind spot feature_usage_daily exists to close, and the UI says so
     * rather than letting the number pass as complete.
     */
    private function activeAccountCount(): int
    {
        return (int) DB::table('campaigns')
            ->join('customers', 'customers.id', '=', 'campaigns.customer_id')
            ->whereNull('customers.deleted_at')
            ->where('customers.is_sandbox', false)
            ->whereBetween('campaigns.updated_at', [$this->period->since, $this->period->until])
            ->distinct()
            ->count('customers.id');
    }
}
