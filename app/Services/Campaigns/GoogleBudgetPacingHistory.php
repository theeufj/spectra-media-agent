<?php

namespace App\Services\Campaigns;

use App\Models\AgentActivity;
use App\Models\Campaign;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Confirmed platform timing is distinct from saving a desired local budget. */
class GoogleBudgetPacingHistory
{
    public const ACTION = 'google_budget_pacing_confirmed';

    public function latest(Campaign $campaign): ?array
    {
        $activity = AgentActivity::where('campaign_id', $campaign->id)->where('customer_id', $campaign->customer_id)
            ->where('action', self::ACTION)->where('status', 'completed')->latest('id')->first();

        return $activity?->details;
    }

    public function record(Campaign $campaign, array $readback): void
    {
        DB::transaction(function () use ($campaign, $readback): void {
            $locked = Campaign::withoutCustomerScope()->lockForUpdate()->findOrFail($campaign->id);
            if (! $locked instanceof Campaign) {
                throw new \RuntimeException('Campaign was unavailable while recording budget confirmation.');
            }
            $previous = $this->latest($locked);
            $changed = $locked->last_budget_changed_at;
            // Hourly reconciliation often reasserts an unchanged budget. It
            // must not continually restart an already confirmed pacing window.
            if ($previous && $this->matches($previous, $readback)
                && (! $changed || CarbonImmutable::parse($previous['confirmed_at'])->gte($changed))) {
                return;
            }
            AgentActivity::record('budget_pacing', self::ACTION,
                'Google confirmed the daily budget for "'.$locked->name.'". Its pacing window starts from this readback.',
                $locked->customer_id, $locked->id, $readback + [
                    'confirmed_at' => now()->toIso8601String(), 'source' => 'google_budget_mutation_readback',
                ]);
        });
    }

    public function matches(array $proof, array $snapshot): bool
    {
        return ($proof['source'] ?? null) === 'google_budget_mutation_readback'
            && is_string($proof['confirmed_at'] ?? null) && $proof['confirmed_at'] !== ''
            && is_string($proof['campaign_resource'] ?? null)
            && preg_match('#^customers/(\d+)/campaigns/\d+$#', $proof['campaign_resource'], $resource)
            && is_string($proof['budget_resource'] ?? null)
            && preg_match('#^customers/'.$resource[1].'/campaignBudgets/\d+$#', $proof['budget_resource'])
            && $proof['campaign_resource'] === ($snapshot['campaign_resource'] ?? null)
            && $proof['budget_resource'] === ($snapshot['budget_resource'] ?? null)
            && is_int($proof['daily_budget_micros'] ?? null) && $proof['daily_budget_micros'] > 0
            && $proof['daily_budget_micros'] === ($snapshot['daily_budget_micros'] ?? null)
            && ($proof['currency_code'] ?? null) === ($snapshot['currency_code'] ?? null)
            && ($proof['account_timezone'] ?? null) === ($snapshot['account_timezone'] ?? null);
    }
}
