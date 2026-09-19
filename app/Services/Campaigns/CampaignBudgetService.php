<?php

namespace App\Services\Campaigns;

use App\Contracts\Ads\AdsServiceFactory;
use App\Models\Campaign;
use App\Services\FacebookAds\AdSetService;
use App\Services\FacebookAds\CampaignService as FacebookCampaignService;
use Illuminate\Support\Facades\Cache;

/** One budget envelope for every platform; allocations and rounding use cents. */
class CampaignBudgetService
{
    public function __construct(private AdsServiceFactory $ads) {}

    public function ceiling(Campaign $campaign): float
    {
        return min((float) $campaign->daily_budget, (float) ($campaign->approved_daily_budget ?? $campaign->daily_budget))
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
        return Cache::lock("campaign-budget:{$campaign->id}", 180)->block(10, function () use ($campaign, $requested) {
            if ($campaign->exists) {
                $campaign->refresh();
            }
            $customer = $campaign->customer;
            if (! $customer) {
                return false;
            }
            $ok = true;
            $attempted = false;
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
                            $ok = $this->ads->budgets($customer)->updateDailyBudget($customer->cleanGoogleCustomerId(), $resource, $share * 10000) && $ok;
                        }
                    } elseif ($platform === 'facebook' && $campaign->facebook_ads_campaign_id && $customer->facebook_ads_account_id) {
                        $attempted = true;
                        // Never route sandbox customers through a live Meta service.
                        if ($customer->is_sandbox) {
                            continue;
                        }
                        $service = new FacebookCampaignService($customer);
                        $remote = $service->getCampaign($campaign->facebook_ads_campaign_id);
                        if (! $remote) {
                            $ok = false;
                        } elseif (! empty($remote['daily_budget'])) {
                            $ok = $service->updateCampaign($campaign->facebook_ads_campaign_id, ['daily_budget' => $cents]) && $ok;
                        } else {
                            $adSets = new AdSetService($customer);
                            $weights = [];
                            foreach ($adSets->listAdSets($campaign->facebook_ads_campaign_id) as $adSet) {
                                if (($adSet['daily_budget'] ?? 0) > 0) {
                                    $weights[$adSet['id']] = (int) $adSet['daily_budget'];
                                }
                            }
                            $ok = $weights !== [] && $ok;
                            foreach (self::split($cents, $weights) as $id => $share) {
                                // Do not inflate each share to an API minimum and exceed the envelope.
                                $ok = $adSets->updateAdSet((string) $id, ['daily_budget' => $share]) && $ok;
                            }
                        }
                    } elseif ($platform === 'microsoft' && $campaign->microsoft_ads_campaign_id && $customer->microsoft_ads_account_id) {
                        $attempted = true;
                        $ok = ($customer->is_sandbox || (new \App\Services\MicrosoftAds\CampaignService($customer))->updateBudget($campaign->microsoft_ads_campaign_id, $cents / 100)) && $ok;
                    } elseif ($platform === 'linkedin' && $campaign->linkedin_campaign_id && $customer->linkedin_ads_account_id) {
                        $attempted = true;
                        $ok = ($customer->is_sandbox || (new \App\Services\LinkedInAds\CampaignService($customer))->updateBudget($campaign->linkedin_campaign_id, $cents / 100)) && $ok;
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $ok = false;
                }
            }

            return $attempted && $ok;
        });
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
