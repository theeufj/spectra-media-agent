<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\Campaigns\SettleDeployedCampaign;
use Illuminate\Console\Command;

/**
 * Find campaigns that are serving while their lifecycle status says they are not.
 *
 * SettleDeployedCampaign closes the gap going forward. It does nothing for rows
 * already in the state, and those are the expensive ones: `campaigns.status` is
 * what CampaignFetchPerformanceData, HealthCheck, SelfHealing,
 * AudienceIntelligence, PortfolioOptimization, BudgetAllocation and
 * DetectKeywordCannibalization all filter on, while ProcessDailyAdSpendBilling
 * also matches `platform_status = ENABLED`. A campaign stuck at draft is
 * therefore billed daily and looked at by nothing — no performance data is
 * fetched for it, so it does not appear in reports either.
 *
 * Reports by default. Pass --fix to settle them.
 */
class ReconcileCampaignLifecycle extends Command
{
    protected $signature = 'campaigns:reconcile-lifecycle {--fix : Settle the campaigns rather than only listing them}';

    protected $description = 'Find campaigns whose ads are serving while their status still says draft';

    /**
     * Proof that a strategy reached the platform.
     *
     * Deliberately narrow. `deployment_status` also holds 'failed',
     * 'skipped_plan', 'deploying' and 'deploy_unverified' — the last meaning
     * "we could not check", which is not a reason to mark a campaign live and
     * hand it to the agents that spend against it. `deployed_at` is only ever
     * written on a successful execution.
     */
    private const SERVING = ['deployed', 'verified', 'active'];

    public function handle(SettleDeployedCampaign $settler): int
    {
        $stuck = Campaign::query()
            ->whereIn('status', [CampaignStatus::Draft, CampaignStatus::PendingAdminDeployment])
            ->whereHas('strategies', function ($query) {
                $query->whereNotNull('deployed_at')
                    ->orWhereIn('deployment_status', self::SERVING);
            })
            ->with('customer')
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No campaigns are serving while marked draft.');

            return self::SUCCESS;
        }

        $this->warn($stuck->count().' campaign(s) are serving while marked draft:');

        $this->table(
            ['ID', 'Customer', 'Campaign', 'Status', 'Platform status', 'Serving strategies'],
            $stuck->map(fn (Campaign $campaign) => [
                $campaign->id,
                $campaign->customer->name,
                $campaign->name,
                $campaign->status->value,
                $campaign->platform_status ?? '—',
                $campaign->strategies
                    ->filter(fn ($s) => $s->deployed_at !== null || in_array($s->deployment_status, self::SERVING, true))
                    ->count(),
            ])->all(),
        );

        if (! $this->option('fix')) {
            $this->line('');
            $this->line('Re-run with --fix to settle them. Setup-only customers are paused on the');
            $this->line('platform rather than marked active, as their receipt email promises.');

            return self::SUCCESS;
        }

        $moved = 0;

        foreach ($stuck as $campaign) {
            // Per campaign, so one customer's platform refusing a pause does not
            // abandon the rest of the run.
            try {
                if ($settler->settle($campaign)) {
                    $moved++;
                    $this->line("  settled campaign {$campaign->id} → ".$campaign->fresh()->status->value);
                }
            } catch (\Throwable $e) {
                report($e);
                $this->error("  campaign {$campaign->id}: ".$e->getMessage());
            }
        }

        $this->info("Settled {$moved} campaign(s).");

        return self::SUCCESS;
    }
}
