<?php

namespace App\Services\Analytics;

use App\Models\Campaign;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where every account is stuck on the way to running ads.
 *
 * An audit of production found 14 of 15 accounts had never created a campaign
 * and only one had an ad-spend credit account — none of which was visible
 * anywhere, because nothing measured the gap between "signed up" and "running
 * ads". The activation funnel counts how many accounts reached each step; this
 * names the ones that did not, and says what is blocking each.
 *
 * BLOCKERS ARE GRADED, because "we are waiting on Google" and "nobody has
 * started" need different people to do different things:
 *
 *   blocked — cannot advertise at all until someone acts
 *   stalled — work was started and did not finish
 *   setup   — will run, but is under-instrumented (tracking, GTM)
 *
 * Every figure comes from an aggregate query keyed by customer_id, never a
 * per-account lookup in a loop. Fifteen accounts would forgive that; the point
 * of measuring adoption is that the number grows.
 */
class AccountReadiness
{
    /** A strategy generation still unfinished after this long is stuck, not slow. */
    private const STRATEGY_STUCK_HOURS = 6;

    public const SEVERITY_BLOCKED = 'blocked';

    public const SEVERITY_STALLED = 'stalled';

    public const SEVERITY_SETUP = 'setup';

    /**
     * @return array{
     *     accounts: list<array<string, mixed>>,
     *     summary: array<string, int>,
     *     blocker_counts: list<array{key: string, label: string, severity: string, accounts: int}>
     * }
     */
    public function report(): array
    {
        $customers = DB::table('customers')
            ->whereNull('deleted_at')
            ->where('is_sandbox', false)
            ->select([
                'id', 'name', 'created_at',
                'google_ads_customer_id', 'facebook_ads_account_id',
                'microsoft_ads_customer_id', 'linkedin_ads_account_id',
                'google_ads_link_status', 'google_ads_link_requested_at',
                'conversion_tracking_verified_at', 'gtm_installed',
            ])
            ->orderBy('created_at')
            ->get();

        $ids = $customers->pluck('id')->all();

        $campaigns = $this->campaignFacts($ids);
        $credits = $this->creditFacts($ids);
        $seats = $this->seatCounts($ids);

        $accounts = [];

        foreach ($customers as $customer) {
            $facts = $campaigns[$customer->id] ?? null;
            $credit = $credits[$customer->id] ?? null;

            $blockers = $this->blockersFor($customer, $facts, $credit);

            $accounts[] = [
                'id' => $customer->id,
                'name' => $customer->name,
                'signed_up' => (string) $customer->created_at,
                'age_days' => (int) now()->diffInDays($customer->created_at),
                'seats' => (int) ($seats[$customer->id] ?? 0),
                'campaigns' => (int) ($facts->total ?? 0),
                'live' => (int) ($facts->serving ?? 0),
                'blockers' => $blockers,
                // The worst thing wrong with this account, for sorting and for
                // the "what should someone do first" question.
                'severity' => $this->worstSeverity($blockers),
            ];
        }

        return [
            'accounts' => $accounts,
            'summary' => $this->summarise($accounts),
            'blocker_counts' => $this->countBlockers($accounts),
        ];
    }

    /**
     * @return list<array{key: string, label: string, severity: string, detail: ?string}>
     */
    private function blockersFor(object $customer, ?object $facts, ?object $credit): array
    {
        $blockers = [];

        $hasAnyPlatform = $customer->google_ads_customer_id
            || $customer->facebook_ads_account_id
            || $customer->microsoft_ads_customer_id
            || $customer->linkedin_ads_account_id;

        if (! $hasAnyPlatform) {
            $blockers[] = $this->blocker('no_platform_account', 'No ad platform account', self::SEVERITY_BLOCKED);
        } elseif ($customer->google_ads_link_status === 'pending') {
            $waiting = $customer->google_ads_link_requested_at
                ? (int) now()->diffInDays($customer->google_ads_link_requested_at)
                : null;

            $blockers[] = $this->blocker(
                'google_link_pending',
                'Google Ads link not accepted',
                self::SEVERITY_BLOCKED,
                // The customer has to accept the invitation in their own Google
                // account, so the age is the whole story: a day is normal, a
                // fortnight means the email was missed.
                $waiting !== null ? "waiting {$waiting}d" : 'no request recorded',
            );
        }

        if (! $credit) {
            $blockers[] = $this->blocker(
                'no_ad_spend_credit',
                'No ad-spend credit account',
                self::SEVERITY_BLOCKED,
                'campaigns cannot be billed',
            );
        } elseif (in_array($credit->status, ['depleted', 'suspended'], true)
            || in_array($credit->payment_status, ['failed', 'paused'], true)) {
            $blockers[] = $this->blocker(
                'credit_unhealthy',
                'Ad-spend credit needs attention',
                self::SEVERITY_BLOCKED,
                "{$credit->status} / {$credit->payment_status}",
            );
        }

        $total = (int) ($facts->total ?? 0);

        if ($total === 0) {
            $blockers[] = $this->blocker('no_campaigns', 'No campaign ever created', self::SEVERITY_STALLED);
        } else {
            if ((int) ($facts->strategy_failed ?? 0) > 0) {
                $blockers[] = $this->blocker(
                    'strategy_failed', 'Strategy generation failed', self::SEVERITY_STALLED,
                    "{$facts->strategy_failed} campaign(s)",
                );
            }

            if ((int) ($facts->strategy_stuck ?? 0) > 0) {
                $blockers[] = $this->blocker(
                    'strategy_stuck', 'Strategy generation never finished', self::SEVERITY_STALLED,
                    // Started, no completion, no error — nothing will retry it
                    // and nothing reports it. Invisible without this.
                    "{$facts->strategy_stuck} campaign(s), no error recorded",
                );
            }

            if ((int) ($facts->deploy_failed ?? 0) > 0) {
                $blockers[] = $this->blocker(
                    'deploy_failed', 'Deployment failed', self::SEVERITY_STALLED,
                    "{$facts->deploy_failed} strategy(s)",
                );
            }

            if ((int) ($facts->deployed ?? 0) === 0) {
                $blockers[] = $this->blocker(
                    'never_deployed', 'Campaigns created but never deployed', self::SEVERITY_STALLED,
                    "{$total} campaign(s)",
                );
            } elseif ((int) ($facts->serving ?? 0) === 0) {
                $blockers[] = $this->blocker(
                    'nothing_serving', 'Deployed but nothing is serving', self::SEVERITY_STALLED,
                );
            }

            if ((int) ($facts->active_unreconciled ?? 0) > 0) {
                $blockers[] = $this->blocker(
                    'never_reconciled', 'Marked active but never checked against the platform',
                    self::SEVERITY_STALLED,
                    // We are billing on a status nothing has verified — the
                    // BILL-8 shape.
                    "{$facts->active_unreconciled} campaign(s)",
                );
            }
        }

        if ($customer->conversion_tracking_verified_at === null) {
            $blockers[] = $this->blocker(
                'tracking_unverified', 'Conversion tracking unverified', self::SEVERITY_SETUP,
                'optimisation is flying blind',
            );
        }

        if (! $customer->gtm_installed) {
            $blockers[] = $this->blocker('gtm_missing', 'GTM container not installed', self::SEVERITY_SETUP);
        }

        return $blockers;
    }

    /**
     * @return array{key: string, label: string, severity: string, detail: ?string}
     */
    private function blocker(string $key, string $label, string $severity, ?string $detail = null): array
    {
        return ['key' => $key, 'label' => $label, 'severity' => $severity, 'detail' => $detail];
    }

    /**
     * One row per customer with every campaign-derived fact.
     *
     * @param  list<int>  $ids
     */
    private function campaignFacts(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $serving = Campaign::SERVING_PRIMARY_STATUSES;
        $placeholders = implode(',', array_fill(0, count($serving), '?'));
        $idList = implode(',', array_map('intval', $ids));
        $stuckBefore = now()->subHours(self::STRATEGY_STUCK_HOURS)->toDateTimeString();

        return collect(DB::select(
            <<<SQL
            SELECT
                ca.customer_id,
                COUNT(*)                                                         AS total,
                COUNT(*) FILTER (WHERE ca.primary_status IN ({$placeholders}))   AS serving,
                COUNT(*) FILTER (WHERE ca.strategy_generation_error IS NOT NULL) AS strategy_failed,
                COUNT(*) FILTER (
                    WHERE ca.strategy_generation_started_at IS NOT NULL
                      AND ca.strategy_generation_completed_at IS NULL
                      AND ca.strategy_generation_error IS NULL
                      AND ca.strategy_generation_started_at < ?
                )                                                                AS strategy_stuck,
                COUNT(*) FILTER (WHERE ca.status = 'active' AND ca.primary_status IS NULL)
                                                                                 AS active_unreconciled,
                COUNT(*) FILTER (WHERE EXISTS (
                    SELECT 1 FROM strategies s
                    WHERE s.campaign_id = ca.id AND s.deployment_status IN ('deployed','verified')
                ))                                                               AS deployed,
                COUNT(*) FILTER (WHERE EXISTS (
                    SELECT 1 FROM strategies s
                    WHERE s.campaign_id = ca.id AND s.deployment_error IS NOT NULL
                ))                                                               AS deploy_failed
            FROM campaigns ca
            WHERE ca.customer_id IN ({$idList})
            GROUP BY ca.customer_id
            SQL,
            [...$serving, $stuckBefore],
        ))->keyBy('customer_id');
    }

    /**
     * @param  list<int>  $ids
     */
    private function creditFacts(array $ids): Collection
    {
        return DB::table('ad_spend_credits')
            ->whereIn('customer_id', $ids)
            ->select('customer_id', 'status', 'payment_status', 'current_balance')
            ->get()
            ->keyBy('customer_id');
    }

    /**
     * @param  list<int>  $ids
     */
    private function seatCounts(array $ids): Collection
    {
        return DB::table('customer_user')
            ->whereIn('customer_id', $ids)
            ->selectRaw('customer_id, COUNT(*) as seats')
            ->groupBy('customer_id')
            ->pluck('seats', 'customer_id');
    }

    /**
     * @param  list<array{key: string, label: string, severity: string, detail: ?string}>  $blockers
     */
    private function worstSeverity(array $blockers): string
    {
        foreach ([self::SEVERITY_BLOCKED, self::SEVERITY_STALLED, self::SEVERITY_SETUP] as $severity) {
            foreach ($blockers as $blocker) {
                if ($blocker['severity'] === $severity) {
                    return $severity;
                }
            }
        }

        return 'ready';
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return array<string, int>
     */
    private function summarise(array $accounts): array
    {
        return [
            'total' => count($accounts),
            'ready' => count(array_filter($accounts, fn ($a) => $a['severity'] === 'ready')),
            'blocked' => count(array_filter($accounts, fn ($a) => $a['severity'] === self::SEVERITY_BLOCKED)),
            'stalled' => count(array_filter($accounts, fn ($a) => $a['severity'] === self::SEVERITY_STALLED)),
            'setup_only' => count(array_filter($accounts, fn ($a) => $a['severity'] === self::SEVERITY_SETUP)),
            'never_launched' => count(array_filter($accounts, fn ($a) => $a['campaigns'] === 0)),
            'serving' => count(array_filter($accounts, fn ($a) => $a['live'] > 0)),
        ];
    }

    /**
     * How many accounts each blocker affects — the "fix this once, unblock ten
     * accounts" view.
     *
     * @param  list<array<string, mixed>>  $accounts
     * @return list<array{key: string, label: string, severity: string, accounts: int}>
     */
    private function countBlockers(array $accounts): array
    {
        $counts = [];

        foreach ($accounts as $account) {
            foreach ($account['blockers'] as $blocker) {
                $key = $blocker['key'];
                $counts[$key] ??= [
                    'key' => $key,
                    'label' => $blocker['label'],
                    'severity' => $blocker['severity'],
                    'accounts' => 0,
                ];
                $counts[$key]['accounts']++;
            }
        }

        $order = [self::SEVERITY_BLOCKED => 0, self::SEVERITY_STALLED => 1, self::SEVERITY_SETUP => 2];

        $counts = array_values($counts);
        usort($counts, fn ($a, $b) => [$order[$a['severity']], -$a['accounts']] <=> [$order[$b['severity']], -$b['accounts']]);

        return $counts;
    }
}
