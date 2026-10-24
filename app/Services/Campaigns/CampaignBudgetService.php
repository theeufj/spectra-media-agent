<?php

namespace App\Services\Campaigns;

use App\Contracts\Ads\AdsServiceFactory;
use App\Contracts\Ads\ReportsBudgetChanges;
use App\Models\Campaign;
use App\Services\FacebookAds\AdAccountService;
use App\Services\FacebookAds\AdSetService;
use App\Services\FacebookAds\CampaignService as FacebookCampaignService;
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

        return self::split((int) round(max(0, min($requested, $this->ceiling($campaign))) * 100), $weights);
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

    /**
     * @return array{success: bool, changes: list<array{platform: string, resource_name: string, old_budget_micros: int, new_budget_micros: int, currency: string}>}
     */
    public function applyWithChanges(Campaign $campaign, float $requested): array
    {
        return Cache::lock("campaign-budget:{$campaign->id}", 180)->block(10, function () use ($campaign, $requested) {
            if ($campaign->exists) {
                $campaign->refresh();
            }
            $customer = $campaign->customer;
            if (! $customer) {
                return ['success' => false, 'changes' => []];
            }
            $ok = true;
            $attempted = false;
            $changes = [];
            foreach ($this->allocations($campaign, $requested) as $platform => $cents) {
                try {
                    if ($platform === 'google' && $campaign->google_ads_campaign_id && $customer->google_ads_customer_id) {
                        $attempted = true;
                        $targets = [];
                        foreach ($campaign->strategies as $strategy) {
                            if ($this->platform($strategy->platform) === 'google') {
                                $id = $strategy->reusableGoogleCampaignId() ?? 'pending:'.$strategy->id;
                                $targets[$id] = (int) round($strategy->daily_budget * 100);
                            }
                        }
                        $targets = $targets ?: [$campaign->googleAdsResourceName() => 1];
                        foreach (self::split($cents, $targets) as $id => $share) {
                            if (str_starts_with($id, 'pending:')) {
                                continue;
                            }
                            $resource = str_contains($id, '/') ? $id : "customers/{$customer->cleanGoogleCustomerId()}/campaigns/{$id}";
                            $mutator = $this->ads->budgets($customer);
                            if ($mutator instanceof ReportsBudgetChanges) {
                                $result = $mutator->updateDailyBudgetWithChanges($customer->cleanGoogleCustomerId(), $resource, $share * 10000);
                                $ok = $result['success'] && $ok;
                                array_push($changes, ...$result['changes']);
                            } else {
                                // Legacy and sandbox writers cannot prove a live before/after change.
                                $ok = $mutator->updateDailyBudget($customer->cleanGoogleCustomerId(), $resource, $share * 10000) && $ok;
                            }
                        }
                    } elseif ($platform === 'facebook' && $campaign->facebook_ads_campaign_id && $customer->facebook_ads_account_id) {
                        $attempted = true;
                        // Never route sandbox customers through a live Meta service.
                        if ($customer->is_sandbox) {
                            continue;
                        }
                        $service = new FacebookCampaignService($customer);
                        $accountId = preg_replace('/^act_/', '', $customer->facebook_ads_account_id);
                        $account = $this->observe(fn () => (new AdAccountService($customer))->getAdAccount($accountId));
                        $currency = ($account['id'] ?? null) === 'act_'.$accountId ? ($account['currency'] ?? null) : null;
                        $remote = $service->getCampaign($campaign->facebook_ads_campaign_id);
                        if (! $remote) {
                            $ok = false;
                        } elseif (! empty($remote['daily_budget'])) {
                            $updated = $service->updateCampaign($campaign->facebook_ads_campaign_id, ['daily_budget' => $cents]);
                            $ok = $updated && $ok;
                            if ($updated) {
                                $after = $this->observe(fn () => $service->getCampaign($campaign->facebook_ads_campaign_id));
                                if ((string) ($remote['id'] ?? '') === (string) $campaign->facebook_ads_campaign_id
                                    && (string) ($after['id'] ?? '') === (string) $campaign->facebook_ads_campaign_id) {
                                    $this->recordChange($changes, 'facebook', (string) $campaign->facebook_ads_campaign_id,
                                        $this->minorUnits($remote['daily_budget']), $this->minorUnits($after['daily_budget'] ?? null),
                                        $cents * 10000, $currency);
                                }
                            }
                        } else {
                            $adSets = new AdSetService($customer);
                            $weights = [];
                            foreach ($adSets->listAdSets($campaign->facebook_ads_campaign_id) ?? [] as $adSet) {
                                if (($adSet['daily_budget'] ?? 0) > 0) {
                                    $weights[$adSet['id']] = (int) $adSet['daily_budget'];
                                }
                            }
                            $ok = $weights !== [] && $ok;
                            $updatedShares = [];
                            foreach (self::split($cents, $weights) as $id => $share) {
                                // Do not inflate each share to an API minimum and exceed the envelope.
                                $updated = $adSets->updateAdSet((string) $id, ['daily_budget' => $share]);
                                $ok = $updated && $ok;
                                if ($updated) {
                                    $updatedShares[$id] = $share;
                                }
                            }
                            foreach ($updatedShares !== [] ? ($this->observe(fn () => $adSets->listAdSets($campaign->facebook_ads_campaign_id)) ?? []) : [] as $after) {
                                $id = $after['id'] ?? '';
                                if (isset($updatedShares[$id], $weights[$id])) {
                                    $this->recordChange($changes, 'facebook', (string) $id, $weights[$id] * 10000,
                                        $this->minorUnits($after['daily_budget'] ?? null), $updatedShares[$id] * 10000, $currency);
                                }
                            }
                        }
                    } elseif ($platform === 'microsoft' && $campaign->microsoft_ads_campaign_id && $customer->microsoft_ads_account_id) {
                        $attempted = true;
                        // This adapter has no verified account-currency read. Keep reconciling,
                        // but do not turn a successful write into an unproven change receipt.
                        $ok = ($customer->is_sandbox || (new \App\Services\MicrosoftAds\CampaignService($customer))->updateBudget($campaign->microsoft_ads_campaign_id, $cents / 100)) && $ok;
                    } elseif ($platform === 'linkedin' && $campaign->linkedin_campaign_id && $customer->linkedin_ads_account_id) {
                        $attempted = true;
                        if (! $customer->is_sandbox) {
                            $service = new \App\Services\LinkedInAds\CampaignService($customer);
                            $before = $this->observe(fn () => $service->getCampaign($campaign->linkedin_campaign_id));
                            $updated = (bool) $service->updateBudget($campaign->linkedin_campaign_id, $cents / 100);
                            $ok = $updated && $ok;
                            if ($updated) {
                                $after = $this->observe(fn () => $service->getCampaign($campaign->linkedin_campaign_id));
                                $id = preg_replace('/^urn:li:sponsoredCampaign:/', '', $campaign->linkedin_campaign_id);
                                if (preg_replace('/^urn:li:sponsoredCampaign:/', '', (string) ($before['id'] ?? '')) === $id
                                    && preg_replace('/^urn:li:sponsoredCampaign:/', '', (string) ($after['id'] ?? '')) === $id
                                    && ($before['dailyBudget']['currencyCode'] ?? null) === ($after['dailyBudget']['currencyCode'] ?? null)) {
                                    $this->recordChange($changes, 'linkedin', $campaign->linkedin_campaign_id,
                                        $this->decimalMicros($before['dailyBudget']['amount'] ?? null), $this->decimalMicros($after['dailyBudget']['amount'] ?? null),
                                        $cents * 10000, $after['dailyBudget']['currencyCode'] ?? null);
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $ok = false;
                }
            }

            return ['success' => $attempted && $ok, 'changes' => $changes];
        });
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
