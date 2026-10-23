<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Strategy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A customer's approval supersedes unbuilt suggestions, never existing platform spend. */
class CampaignBudgetConfirmation
{
    public function confirm(Campaign $campaign, int $dailyCents): Campaign
    {
        return DB::transaction(function () use ($campaign, $dailyCents) {
            // Match the allocator's lock order so approval and allocation cannot
            // observe different campaign envelopes halfway through an update.
            $current = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $strategies = $current->strategies()->orderBy('id')->lockForUpdate()->get();
            if ($strategies->contains(fn (Strategy $strategy) => in_array($strategy->deployment_status, ['queued', 'deploying'], true))) {
                throw ValidationException::withMessages(['daily_budget' => 'Wait for the current deployment to finish before changing its budget.']);
            }

            $remaining = $dailyCents;
            $drafts = collect();
            foreach ($strategies as $strategy) {
                $strategy->setRelation('campaign', $current);
                if (! $strategy->hasDeploymentFootprint() && $strategy->reusableGoogleCampaignId() === null) {
                    $drafts->push($strategy);

                    continue;
                }
                $amount = $this->cents($strategy->daily_budget);
                if ($amount === null || $amount <= 0 || $amount > $remaining) {
                    throw ValidationException::withMessages(['daily_budget' => 'The existing platform allocations must be verified and fit within this budget before it can be confirmed.']);
                }
                $remaining -= $amount;
            }

            [$approved, $unapproved] = $drafts->partition(fn (Strategy $strategy) => $strategy->reservesDeploymentBudget());
            $approvedTotal = $this->total($approved);
            if ($approvedTotal === null || $approvedTotal > $remaining) {
                $this->clear($drafts);
            } else {
                $unapprovedTotal = $this->total($unapproved);
                if ($unapprovedTotal === null || $unapprovedTotal > $remaining - $approvedTotal) {
                    $this->clear($unapproved);
                }
            }

            $current->update([
                'daily_budget' => $this->decimal($dailyCents),
                'approved_daily_budget' => $this->decimal($dailyCents),
                'total_budget' => $this->decimal($dailyCents * 7),
                'budget_confirmed_at' => now(),
            ]);

            return $current;
        });
    }

    /** @param Collection<int, Strategy> $strategies */
    private function total(Collection $strategies): ?int
    {
        $total = 0;
        foreach ($strategies as $strategy) {
            if ($strategy->daily_budget === null) {
                continue; // The allocator will fill a missing suggestion later.
            }
            $amount = $this->cents($strategy->daily_budget);
            if ($amount === null) {
                return null;
            }
            $total += $amount;
        }

        return $total;
    }

    /** @param Collection<int, Strategy> $strategies */
    private function clear(Collection $strategies): void
    {
        // Do this even on same-amount confirmation: a previous attempt may have
        // saved the approval while retaining the model's larger allocation.
        foreach ($strategies as $strategy) {
            $strategy->update(['daily_budget' => null]);
        }
    }

    private function cents(mixed $amount): ?int
    {
        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            return null;
        }
        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', (string) $amount)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
