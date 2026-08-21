<?php

namespace App\Services\Analytics;

use App\Models\Campaign;
use Illuminate\Support\Facades\DB;

/**
 * The six figures pinned above the tabs, identical on every tab so switching
 * never blanks the top of the page.
 *
 * Deliberately NOT cached. Every query here is a count against an index and the
 * set runs in the low tens of milliseconds; caching would buy nothing and would
 * introduce a class of bug (stale headline numbers sitting above freshly
 * computed tab content) that costs more than it saves.
 */
class UsageSummary
{
    public function __construct(private readonly UsagePeriod $period) {}

    /**
     * @return list<array{key: string, label: string, value: int, sub: ?string, trend: ?float, href: ?string}>
     */
    public function cards(): array
    {
        $since = $this->period->since;
        $until = $this->period->until;

        // Sandbox and soft-deleted accounts are excluded everywhere on this
        // page; a demo account is not a customer.
        $realAccounts = fn () => DB::table('customers')
            ->whereNull('deleted_at')
            ->where('is_sandbox', false);

        $totalAccounts = (int) $realAccounts()->count();
        $newAccounts = (int) $realAccounts()->whereBetween('created_at', [$since, $until])->count();
        $previousNew = (int) $realAccounts()
            ->whereBetween('created_at', [$this->period->previousSince, $this->period->previousUntil])
            ->count();

        $newUsers = (int) DB::table('users')->whereBetween('created_at', [$since, $until])->count();
        $previousUsers = (int) DB::table('users')
            ->whereBetween('created_at', [$this->period->previousSince, $this->period->previousUntil])
            ->count();

        $campaignsBase = fn () => DB::table('campaigns')
            ->join('customers', 'customers.id', '=', 'campaigns.customer_id')
            ->whereNull('customers.deleted_at')
            ->where('customers.is_sandbox', false);

        $liveCampaigns = (int) $campaignsBase()
            ->whereIn('campaigns.primary_status', Campaign::SERVING_PRIMARY_STATUSES)
            ->count();

        $newCampaigns = (int) $campaignsBase()
            ->whereBetween('campaigns.created_at', [$since, $until])
            ->count();

        $previousCampaigns = (int) $campaignsBase()
            ->whereBetween('campaigns.created_at', [$this->period->previousSince, $this->period->previousUntil])
            ->count();

        // Accounts that touched a campaign in the window. Narrow on purpose —
        // read-only activity leaves no row until feature_usage_daily is
        // instrumented, so this under-counts and the UI says so.
        $activeAccounts = (int) $campaignsBase()
            ->whereBetween('campaigns.updated_at', [$since, $until])
            ->distinct()
            ->count('customers.id');

        return [
            [
                'key' => 'accounts',
                'label' => 'Accounts',
                'value' => $totalAccounts,
                'sub' => 'excluding sandbox',
                'trend' => null,
                'href' => null,
            ],
            [
                'key' => 'new_accounts',
                'label' => 'New accounts',
                'value' => $newAccounts,
                'sub' => $this->period->label,
                'trend' => UsagePeriod::trend($newAccounts, $previousNew),
                'href' => null,
            ],
            [
                'key' => 'new_users',
                'label' => 'New signups',
                'value' => $newUsers,
                'sub' => $this->period->label,
                'trend' => UsagePeriod::trend($newUsers, $previousUsers),
                'href' => null,
            ],
            [
                'key' => 'active_accounts',
                'label' => 'Active accounts',
                'value' => $activeAccounts,
                'sub' => $totalAccounts > 0
                    ? round($activeAccounts / $totalAccounts * 100, 1).'% of all accounts'
                    : null,
                'trend' => null,
                'href' => null,
            ],
            [
                'key' => 'new_campaigns',
                'label' => 'New campaigns',
                'value' => $newCampaigns,
                'sub' => $this->period->label,
                'trend' => UsagePeriod::trend($newCampaigns, $previousCampaigns),
                'href' => null,
            ],
            [
                'key' => 'live_campaigns',
                'label' => 'Live campaigns',
                'value' => $liveCampaigns,
                'sub' => 'platform reports serving',
                'trend' => null,
                'href' => null,
            ],
        ];
    }
}
