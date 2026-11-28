<?php

namespace App\Services\Campaigns;

use App\Contracts\Ads\AdsServiceFactory;
use App\Contracts\Ads\ReportsBudgetChanges;
use App\Enums\CampaignStatus;
use App\Models\AdSpendCredit;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\FacebookAds\AdAccountService;
use App\Services\FacebookAds\AdSetService;
use App\Services\FacebookAds\CampaignService as FacebookCampaignService;
use App\Services\GoogleAds\CommonServices\GoogleBudgetMutator;
use App\Services\LinkedInAds\CampaignService as LinkedInCampaignService;
use App\Services\MicrosoftAds\CampaignService as MicrosoftCampaignService;
use Illuminate\Support\Facades\Cache;

/** One budget envelope for every platform; allocations and rounding use cents. */
class CampaignBudgetService
{
    public function __construct(private AdsServiceFactory $ads) {}

    public function ceiling(Campaign $campaign): float
    {
        $trial = CampaignSpendGuardrails::activeTrial($campaign);

        return min((float) $campaign->daily_budget, (float) ($campaign->approved_daily_budget ?? $campaign->daily_budget),
            $trial ? (int) $trial['max_daily_budget_micros'] / 1_000_000 : PHP_FLOAT_MAX)
            * max(0, min(1, (float) ($campaign->billing_budget_multiplier ?? 1)));
    }

    /** @return array<string, int> Platform allocations in cents, including undeployed shares. */
    public function allocations(Campaign $campaign, float $requested): array
    {
        $weights = [];
        foreach ($campaign->strategies as $strategy) {
            $platform = $this->platform($strategy->platform);
            if ($platform && $strategy->daily_budget > 0) {
                $weights[$platform] = ($weights[$platform] ?? 0) + (int) round($strategy->daily_budget * 100);
            }
        }
        if ($weights === []) {
            foreach (['google' => 'google_ads_campaign_id', 'facebook' => 'facebook_ads_campaign_id', 'microsoft' => 'microsoft_ads_campaign_id', 'linkedin' => 'linkedin_campaign_id'] as $platform => $field) {
                if ($campaign->$field) {
                    $weights[$platform] = 1;
                }
            }
        }

        $ceilingCents = (int) floor(round(max(0, $this->ceiling($campaign)) * 100, 6));

        return self::split(min((int) round(max(0, $requested) * 100), $ceilingCents), $weights);
    }

    /** @param array<string, int> $weights @return array<string, int> */
    public static function split(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        $result = [];
        $remaining = $total;
        foreach ($weights as $key => $weight) {
            $result[$key] = $sum > 0 ? (int) floor($total * $weight / $sum) : 0;
            $remaining -= $result[$key];
        }
        foreach (array_keys($result) as $key) {
            if ($remaining-- <= 0) {
                break;
            }
            $result[$key]++;
        }

        return $result;
    }

    public function apply(Campaign $campaign, float $requested): bool
    {
        return $this->applyWithChanges($campaign, $requested)['success'];
    }

    public function automaticHoldReason(Campaign $campaign): ?string
    {
        if (CampaignSpendGuardrails::hasGoogleTotalBudget($campaign)) {
            return 'The approved Google Search total budget is fixed for the campaign dates.';
        }
        if (CampaignSpendGuardrails::automaticChangesSuspended($campaign)) {
            return 'A bounded restart trial or spending hold is in effect.';
        }
        if (in_array($campaign->status, [CampaignStatus::Paused, CampaignStatus::Completed, CampaignStatus::Ended], true)
            || $campaign->hasPassedEndDate()) {
            return 'The campaign is paused or has ended.';
        }
        if ($campaign->customer?->service_type === 'setup_only'
            || $campaign->customer?->google_ads_link_status === 'revoked') {
            return 'Automatic management is unavailable for this account.';
        }
        $credit = $campaign->customer?->adSpendCredit()->first();
        if (in_array($credit?->payment_status, [AdSpendCredit::PAYMENT_PAUSED, AdSpendCredit::PAYMENT_FAILED], true)
            || ($credit && ! $campaign->customer->isSelfFundedAds() && ! $credit->canRunCampaigns())
            || (float) ($campaign->billing_budget_multiplier ?? 1) <= 0) {
            return 'Billing has suspended automatic budget updates.';
        }

        return null;
    }

    /**
     * @return array{success: bool, changes: list<array{platform: string, resource_name: string, old_budget_micros: int, new_budget_micros: int, currency: string}>, errors?: list<string>, read_only_reason?: string}
     */
    public function applyWithChanges(Campaign $campaign, float $requested, bool $automatic = false): array
    {
        return Cache::lock("campaign-budget:{$campaign->id}", 180)->block(10, function () use ($campaign, $requested, $automatic) {
            if ($campaign->exists) {
                $campaign->refresh();
            }
            $customer = $campaign->customer;
            if (! $customer) {
                return ['success' => false, 'changes' => []];
            }
            if ($automatic && ($reason = $this->automaticHoldReason($campaign))) {
                return ['success' => true, 'changes' => [], 'read_only_reason' => $reason];
            }
            if (CampaignSpendGuardrails::hasGoogleTotalBudget($campaign)) {
                return ['success' => false, 'changes' => [], 'errors' => ['This campaign has an approved Google Search total budget.']];
            }
            $attempted = false;
            $changes = [];
            $errors = [];
            foreach ($this->allocations($campaign, $requested) as $platform => $cents) {
                if ($automatic && $cents <= 0) {
                    // Suspending delivery belongs to the campaign/billing lifecycle.
                    continue;
                }
                try {
                    $reconciled = match ($platform) {
                        'google' => $this->reconcileGoogle($campaign, $customer, $cents, $automatic, $changes),
                        'facebook' => $this->reconcileMeta($campaign, $customer, $cents, $automatic, $changes),
                        'microsoft' => $this->reconcileMicrosoft($campaign, $customer, $cents, $automatic),
                        'linkedin' => $this->reconcileLinkedIn($campaign, $customer, $cents, $automatic, $changes),
                    };
                    if ($reconciled === null) {
                        // A planned platform share stays reserved until deployment.
                        continue;
                    }
                    $attempted = true;
                    if (! $reconciled) {
                        $errors[] = $platform;
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $errors[] = $platform;
                }
            }

            return ['success' => $errors === [] && ($attempted || $automatic), 'changes' => $changes]
                + ($errors === [] ? [] : ['errors' => $errors]);
        });
    }

    private function reconcileGoogle(Campaign $campaign, Customer $customer, int $cents, bool $automatic, array &$changes): ?bool
    {
        $entries = collect(CampaignResourceInventory::entries($campaign))->where('platform', 'google');
        if ($entries->isEmpty()) {
            return $campaign->google_ads_campaign_id ? false : null;
        }
        $accountId = $customer->cleanGoogleCustomerId();
        $prefix = "customers/{$accountId}/campaigns/";
        $targets = $this->budgetTargets($campaign, 'google', $entries);
        $paused = $automatic ? $campaign->strategies->where('deployment_status', 'paused')
            ->mapWithKeys(fn ($s) => [($entries->firstWhere('strategy_id', $s->id)['resource_name'] ?? 'pending:'.$s->id) => true])->all() : [];
        $ok = true;
        foreach (self::split($cents, $targets) as $resource => $share) {
            if (isset($paused[$resource]) || str_starts_with($resource, 'pending:') || ($automatic && $share <= 0)) {
                continue;
            }
            if (! str_starts_with($resource, $prefix) || ! ctype_digit(substr($resource, strlen($prefix)))) {
                $ok = false;

                continue;
            }
            try {
                $mutator = $this->ads->budgets($customer);
                if ($automatic && $mutator instanceof GoogleBudgetMutator) {
                    $result = $mutator->reconcileDailyBudget($accountId, $resource, $share * 10000);
                } elseif ($mutator instanceof ReportsBudgetChanges) {
                    $result = $mutator->updateDailyBudgetWithChanges($accountId, $resource, $share * 10000);
                } else {
                    // Synthetic writers have no live change to report.
                    $ok = $mutator->updateDailyBudget($accountId, $resource, $share * 10000) && $ok;

                    continue;
                }
                $ok = $result['success'] && $ok;
                array_push($changes, ...$result['changes']);
            } catch (\Throwable $e) {
                report($e);
                $ok = false;
            }
        }

        return $ok;
    }

    private function reconcileMeta(Campaign $campaign, Customer $customer, int $cents, bool $automatic, array &$changes): ?bool
    {
        $entries = collect(CampaignResourceInventory::entries($campaign))->where('platform', 'facebook');
        if ($entries->isEmpty()) {
            return $campaign->facebook_ads_campaign_id ? false : null;
        }
        if ($customer->is_sandbox) {
            return true;
        }
        $targets = $this->budgetTargets($campaign, 'facebook', $entries);
        $ok = true;
        foreach (self::split($cents, $targets) as $id => $share) {
            $entry = $entries->firstWhere('resource_id', (string) $id);
            if (! $entry || ($automatic && ($share <= 0 || $campaign->strategies->firstWhere('id', $entry['strategy_id'])?->deployment_status === 'paused'))) {
                continue;
            }
            $ok = $this->reconcileMetaResource($customer, (string) $id, $share, $automatic, $changes) && $ok;
        }

        return $ok;
    }

    private function budgetTargets(Campaign $campaign, string $platform, \Illuminate\Support\Collection $entries): array
    {
        $targets = [];
        foreach ($campaign->strategies as $strategy) {
            if ($this->platform($strategy->platform) !== $platform) {
                continue;
            }
            $resource = $entries->firstWhere('strategy_id', $strategy->id)['resource_name'] ?? 'pending:'.$strategy->id;
            $targets[$resource] = (int) round($strategy->daily_budget * 100);
        }
        if (! $targets && $entries->count() === 1) {
            $targets[$entries->first()['resource_name']] = 1;
        }

        return $targets;
    }

    private function reconcileMetaResource(Customer $customer, string $id, int $cents, bool $automatic, array &$changes): bool
    {
        $service = new FacebookCampaignService($customer);
        $accountId = preg_replace('/^act_/', '', $customer->facebook_ads_account_id);
        $before = $service->getCampaign($id);
        if (! $before || (string) ($before['id'] ?? '') !== $id
            || ($automatic && (string) ($before['account_id'] ?? '') !== $accountId)) {
            return false;
        }
        if ($automatic && empty($before['status'])) {
            return false;
        }
        if ($automatic && ($before['status'] !== 'ACTIVE' || ($before['lifetime_budget'] ?? 0) > 0)) {
            return true;
        }

        $target = $cents * 10000;
        $previous = $this->minorUnits($before['daily_budget'] ?? null);
        if ($previous !== null && $previous > 0) {
            if ($previous === $target) {
                return true;
            }
            $currency = $this->metaCurrency($customer, $accountId);
            if (($automatic && $currency === null) || ! $service->updateCampaign($id, ['daily_budget' => $cents])) {
                return false;
            }

            $after = $this->observe(fn () => $service->getCampaign($id));
            $confirmed = (string) ($after['id'] ?? '') === $id
                && (! $automatic || (string) ($after['account_id'] ?? '') === $accountId)
                && $this->minorUnits($after['daily_budget'] ?? null) === $target;
            if ($confirmed) {
                $this->recordChange($changes, 'facebook', $id, $previous, $target, $target, $currency);
            }

            return $confirmed || ! $automatic;
        }

        return $this->reconcileMetaAdSets($id, $customer, $accountId, $cents, $automatic, $changes);
    }

    private function metaCurrency(Customer $customer, string $accountId): ?string
    {
        $account = $this->observe(fn () => (new AdAccountService($customer))->getAdAccount($accountId));
        $currency = ($account['id'] ?? null) === 'act_'.$accountId ? ($account['currency'] ?? null) : null;

        return is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) ? $currency : null;
    }

    private function reconcileMetaAdSets(string $id, Customer $customer, string $accountId, int $cents, bool $automatic, array &$changes): bool
    {
        $service = new AdSetService($customer);
        $adSets = $service->listAdSets($id);
        if ($adSets === null) {
            return false;
        }
        $weights = [];
        $inactive = [];
        foreach ($adSets as $adSet) {
            if (! empty($adSet['id']) && ($adSet['daily_budget'] ?? 0) > 0) {
                $weights[$adSet['id']] = (int) $adSet['daily_budget'];
                if ($automatic && ($adSet['status'] ?? null) !== 'ACTIVE') {
                    $inactive[$adSet['id']] = true;
                }
            }
        }
        if ($weights === []) {
            return $automatic; // No daily budgets to adjust on this placement.
        }

        // Derive the same target shares again after a partial write. Once the
        // requested amount or the ad-set configuration changes, read fresh weights.
        if ($automatic) {
            ksort($weights);
        }
        $shares = self::split($cents, $weights);
        $intentKey = "meta-budget-intent:{$customer->id}:{$id}";
        if ($automatic) {
            $intent = Cache::get($intentKey);
            if (($intent['total'] ?? null) === $cents && array_keys($intent['shares']) === array_keys($shares)) {
                $shares = $intent['shares'];
            } else {
                Cache::put($intentKey, ['total' => $cents, 'shares' => $shares], now()->addDay());
            }
        }

        $updatedShares = [];
        $ok = true;
        $currency = null;
        foreach ($shares as $adSetId => $share) {
            if (isset($inactive[$adSetId]) || $weights[$adSetId] === $share || ($automatic && $share <= 0)) {
                continue;
            }
            $currency ??= $this->metaCurrency($customer, $accountId);
            if ($automatic && $currency === null) {
                return false;
            }
            // Do not inflate each share to an API minimum and exceed the envelope.
            $updated = $service->updateAdSet((string) $adSetId, ['daily_budget' => $share]);
            $ok = $updated && $ok;
            if ($updated) {
                $updatedShares[$adSetId] = $share;
            }
        }
        if ($updatedShares !== []) {
            foreach ($this->observe(fn () => $service->listAdSets($id)) ?? [] as $after) {
                $adSetId = $after['id'] ?? null;
                if (isset($updatedShares[$adSetId]) && $this->minorUnits($after['daily_budget'] ?? null) === $updatedShares[$adSetId] * 10000) {
                    $this->recordChange($changes, 'facebook', (string) $adSetId, $weights[$adSetId] * 10000,
                        $updatedShares[$adSetId] * 10000, $updatedShares[$adSetId] * 10000, $currency);
                    unset($updatedShares[$adSetId]);
                }
            }
        }
        $ok = $ok && (! $automatic || $updatedShares === []);
        if ($ok && $automatic) {
            Cache::forget($intentKey);
        }

        return $ok;
    }

    private function reconcileMicrosoft(Campaign $campaign, Customer $customer, int $cents, bool $automatic): ?bool
    {
        $id = $campaign->microsoft_ads_campaign_id;
        if (! $id) {
            return null;
        }
        if (! $customer->microsoft_ads_account_id) {
            return false;
        }
        if ($customer->is_sandbox) {
            return true;
        }

        $service = new MicrosoftCampaignService($customer);
        if (! $automatic) {
            return $service->updateBudget($id, $cents / 100);
        }
        $get = function () use ($service, $id): ?array {
            $row = $service->getCampaign($id)['Campaigns']['Campaign'] ?? [];
            $row = array_is_list($row) ? ($row[0] ?? []) : $row;

            return (string) ($row['Id'] ?? '') === $id ? $row : null;
        };
        $before = $get();
        if (! $before || empty($before['Status'])) {
            return false;
        }

        $target = $cents * 10000;

        // There is no verified account-currency read. Confirm the amount, but
        // do not publish a customer-facing change receipt without currency.
        return $before['Status'] !== 'Active' || $this->decimalMicros($before['DailyBudget'] ?? null) === $target
            || ($service->updateBudget($id, $cents / 100) && $this->decimalMicros($get()['DailyBudget'] ?? null) === $target);
    }

    private function reconcileLinkedIn(Campaign $campaign, Customer $customer, int $cents, bool $automatic, array &$changes): ?bool
    {
        $id = $campaign->linkedin_campaign_id;
        if (! $id) {
            return null;
        }
        if (! $customer->linkedin_ads_account_id) {
            return false;
        }
        if ($customer->is_sandbox) {
            return true;
        }

        $service = new LinkedInCampaignService($customer);
        $campaignId = preg_replace('/^urn:li:sponsoredCampaign:/', '', $id);
        $account = 'urn:li:sponsoredAccount:'.preg_replace('/^urn:li:sponsoredAccount:/', '', $customer->linkedin_ads_account_id);
        $ownsCampaign = static fn (?array $row): bool => preg_replace('/^urn:li:sponsoredCampaign:/', '', (string) ($row['id'] ?? '')) === $campaignId
            && (! $automatic || ($row['account'] ?? null) === $account);
        $before = $this->observe(fn () => $service->getCampaign($campaignId));
        if ($automatic && (! $ownsCampaign($before) || empty($before['status']))) {
            return false;
        }
        if ($automatic && (($before['status'] ?? null) !== 'ACTIVE' || empty($before['dailyBudget']))) {
            return true;
        }

        $target = $cents * 10000;
        $previous = $this->decimalMicros($before['dailyBudget']['amount'] ?? null);
        if ($ownsCampaign($before) && $previous === $target) {
            return true;
        }
        if (! $service->updateBudget($campaignId, $cents / 100)) {
            return false;
        }

        $after = $this->observe(fn () => $service->getCampaign($campaignId));
        $currency = $after['dailyBudget']['currencyCode'] ?? null;
        $confirmed = $ownsCampaign($before) && $ownsCampaign($after) && $currency === ($before['dailyBudget']['currencyCode'] ?? null)
            && $this->decimalMicros($after['dailyBudget']['amount'] ?? null) === $target;
        if ($confirmed) {
            $this->recordChange($changes, 'linkedin', $id, $previous, $target, $target, $currency);
        }

        return $confirmed || ! $automatic;
    }

    private function recordChange(array &$changes, string $platform, string $resource, ?int $before, ?int $after, int $requested, ?string $currency): void
    {
        if ($before === null || $after === null || $after !== $requested || $before === $after
            || ! preg_match('/^[A-Z]{3}$/', $currency ?? '')) {
            return;
        }
        $changes[] = ['platform' => $platform, 'resource_name' => $resource,
            'old_budget_micros' => $before, 'new_budget_micros' => $after, 'currency' => $currency];
    }

    private function observe(callable $read): ?array
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function minorUnits(mixed $amount): ?int
    {
        return is_scalar($amount) && preg_match('/^\d+$/', (string) $amount) ? (int) $amount * 10000 : null;
    }

    private function decimalMicros(mixed $amount): ?int
    {
        if (! is_scalar($amount) || ! preg_match('/^(\d+)(?:\.(\d{1,6}))?$/', (string) $amount, $parts)) {
            return null;
        }

        return (int) $parts[1] * 1_000_000 + (int) str_pad($parts[2] ?? '', 6, '0');
    }

    private function platform(string $name): ?string
    {
        $name = strtolower($name);
        foreach (['google', 'facebook', 'microsoft', 'linkedin'] as $platform) {
            if (str_contains($name, $platform)) {
                return $platform;
            }
        }

        return str_contains($name, 'meta') ? 'facebook' : null;
    }
}
