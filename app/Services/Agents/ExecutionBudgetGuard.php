<?php

namespace App\Services\Agents;

use App\Models\Strategy;
use App\Services\Campaigns\CampaignSpendGuardrails;

/** AI plans can allocate approved spend, but cannot authorize more of it. */
class ExecutionBudgetGuard
{
    public function validate(ExecutionPlan $plan, ExecutionContext $context, string $platform): ValidationResult
    {
        $campaign = $context->campaign->exists ? $context->campaign->fresh() : $context->campaign;
        $strategy = $context->strategy->exists ? $context->strategy->fresh() : $context->strategy;
        if (! $campaign || ! $strategy) {
            return $this->blocked('The campaign or strategy no longer exists.');
        }
        if ($campaign->auto_generated_at && ! $campaign->budget_confirmed_at) {
            return $this->blocked('Confirm the generated campaign budget before deployment.');
        }

        $approved = $this->micros($campaign->approved_daily_budget);
        $allocation = $this->micros($strategy->daily_budget);
        if ($approved === null || $allocation === null) {
            return $this->blocked('A positive approved campaign budget and strategy allocation are required before deployment.');
        }
        if ($allocation > $approved) {
            return $this->blocked('The strategy allocation exceeds the approved campaign daily budget.');
        }

        // Existing allocations survive budget edits and single-strategy retries.
        // Count approved siblings too, rather than giving each the entire envelope.
        if ($campaign->exists) {
            if ((string) $strategy->campaign_id !== (string) $campaign->id) {
                return $this->blocked('The strategy does not belong to this campaign.');
            }
            $siblings = $campaign->strategies()->whereKeyNot($strategy->getKey())
                ->get()->filter(fn (Strategy $sibling) => $sibling->reservesDeploymentBudget());
            $remaining = $approved - $allocation;
            foreach ($siblings as $sibling) {
                $amount = $this->micros($sibling->daily_budget);
                if ($amount === null || $amount > $remaining) {
                    return $this->blocked('The combined strategy allocations exceed or cannot verify the approved campaign daily budget.');
                }
                $remaining -= $amount;
            }
        }

        $platform = strtolower($platform);
        $microsoft = str_contains($platform, 'microsoft');
        $linkedin = str_contains($platform, 'linkedin');
        $remaining = $allocation;
        foreach ($plan->steps as $step) {
            $action = is_array($step) ? ($step['action'] ?? '') : ($step->action ?? '');
            if ($microsoft && $action === 'import_from_google') {
                return $this->blocked('Google account imports cannot verify this strategy’s spending allocation. Create a budgeted Microsoft campaign instead.');
            }
            // Google and Facebook create one campaign from the strategy's budget;
            // only Microsoft and LinkedIn currently execute per-step AI amounts.
            if ((! $microsoft && ! $linkedin) || $action !== 'create_campaign') {
                continue;
            }
            $params = is_array($step)
                ? ($microsoft ? ($step['params'] ?? $step['parameters'] ?? []) : ($step['parameters'] ?? $step['params'] ?? []))
                : ($microsoft ? ($step->params ?? []) : ($step->parameters ?? $step->params ?? []));
            if (! is_array($params)) {
                return $this->blocked('Campaign budget parameters must be an object.');
            }
            $amount = $params['daily_budget'] ?? ($microsoft ? ($params['budget'] ?? $strategy->daily_budget) : $strategy->daily_budget);
            $amount = $this->micros($amount);
            if ($amount === null || $amount > $remaining) {
                return $this->blocked('The execution plan has an invalid daily budget or exceeds the approved strategy allocation.');
            }
            $remaining -= $amount;
        }

        // Planning may take minutes. Executors must use the freshly verified
        // allocation, rather than the earlier model snapshot or a default budget.
        $context->campaign = $campaign;
        $context->strategy = $strategy;

        return new ValidationResult(true);
    }

    private function micros(mixed $amount): ?int
    {
        if ((! is_int($amount) && ! is_float($amount) && ! is_string($amount))
            || is_float($amount) && ! is_finite($amount)) {
            return null;
        }
        $value = is_float($amount) ? json_encode($amount, JSON_PRESERVE_ZERO_FRACTION) : (string) $amount;
        // Bound the whole part to decimal(12,2)'s range before integer arithmetic.
        if (! is_string($value) || ! preg_match('/^\d{1,10}(?:\.\d{1,6})?$/', $value)) {
            return null;
        }
        $micros = CampaignSpendGuardrails::moneyToMicros($value);

        return $micros > 0 ? $micros : null;
    }

    private function blocked(string $message): ValidationResult
    {
        return (new ValidationResult(true))->addError('execution_budget_not_authorized', $message);
    }
}
