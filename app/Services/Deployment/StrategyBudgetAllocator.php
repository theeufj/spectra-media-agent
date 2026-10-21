<?php

namespace App\Services\Deployment;

use App\Models\Campaign;
use App\Models\PlatformBudgetAllocation;
use App\Models\Strategy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Divide only the unallocated approval, with no money created by rounding. */
class StrategyBudgetAllocator
{
    /** @param Collection<int, Strategy> $selected */
    public function allocate(Campaign $campaign, Collection $selected): void
    {
        DB::transaction(function () use ($campaign, $selected) {
            $current = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $approved = $this->cents($current->approved_daily_budget);
            $daily = $this->cents($current->daily_budget);
            if ($approved === null || $daily === null || $approved <= 0 || $daily <= 0) {
                return; // The execution guard explains missing or invalid approval.
            }
            $remaining = min($approved, $daily);
            $strategies = $current->strategies()->orderBy('id')->lockForUpdate()->get();
            $selectedIds = $selected->map(fn (Strategy $strategy) => $strategy->getKey())->all();
            $missing = [];
            foreach ($strategies as $strategy) {
                $included = in_array($strategy->id, $selectedIds)
                    || $strategy->reservesDeploymentBudget();
                if (! $included) {
                    continue;
                }
                $amount = $this->cents($strategy->daily_budget);
                if ($strategy->daily_budget === null || $amount === 0) {
                    if ($strategy->hasDeploymentFootprint()) {
                        return; // Existing platform spend is unknown, not a new allocation.
                    }
                    $missing[$strategy->id] = $strategy;

                    continue;
                }
                if ($amount === null || $amount > $remaining) {
                    return; // Preserve existing allocations; the guard rejects them.
                }
                $remaining -= $amount;
            }

            if ($missing === []) {
                return;
            }
            $allocation = PlatformBudgetAllocation::where('customer_id', $current->customer_id)->first();
            $weights = [];
            foreach ($missing as $id => $strategy) {
                $platform = preg_replace('/[\s_-]*ads$/', '', strtolower(trim($strategy->platform)));
                $field = match ($platform) {
                    'google' => 'google_ads_pct',
                    'facebook', 'meta' => 'facebook_ads_pct',
                    'microsoft', 'bing' => 'microsoft_ads_pct',
                    'linkedin' => 'linkedin_ads_pct',
                    default => null,
                };
                $weights[$id] = $allocation && $allocation->strategy !== 'manual' && $field
                    ? ($this->cents($allocation->getAttribute($field)) ?? 0) : 0;
                if ($weights[$id] > 10000) {
                    $weights[$id] = 0;
                }
            }
            if (min($weights) === 0) {
                $weights = array_fill_keys(array_keys($missing), 1);
            }
            $totalWeight = array_sum($weights);
            $budgets = $remainders = [];
            foreach ($weights as $id => $weight) {
                $numerator = $remaining * $weight;
                $budgets[$id] = intdiv($numerator, $totalWeight);
                $remainders[$id] = $numerator % $totalWeight;
            }
            $leftover = $remaining - array_sum($budgets);
            // Stable ID order breaks equal-remainder ties, independent of which
            // strategy was requested or the order its relation was loaded in.
            arsort($remainders, SORT_NUMERIC);
            foreach ($remainders as $id => $remainder) {
                if ($leftover <= 0) {
                    break;
                }
                $budgets[$id]++;
                $leftover--;
            }
            foreach ($budgets as $id => $amount) {
                $missing[$id]->update(['daily_budget' => intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT)]);
            }
            foreach ($selected as $strategy) {
                $fresh = $strategies->firstWhere('id', $strategy->id);
                if ($fresh) {
                    $strategy->setAttribute('daily_budget', $fresh->daily_budget);
                    $strategy->syncOriginalAttribute('daily_budget');
                }
            }
        });
    }

    private function cents(mixed $amount): ?int
    {
        if ((! is_int($amount) && ! is_float($amount) && ! is_string($amount))
            || is_float($amount) && ! is_finite($amount)) {
            return null;
        }
        $value = (string) $amount;
        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $value)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
