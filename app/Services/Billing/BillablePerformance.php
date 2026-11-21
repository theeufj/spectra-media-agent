<?php

namespace App\Services\Billing;

use App\Models\AdSpendCredit;
use App\Models\Customer;
use App\Services\Campaigns\CampaignResourceInventory;
use Illuminate\Database\Eloquent\Builder;

/** Use the same account/payer attribution for reporting and manual reconciliation. */
class BillablePerformance
{
    public function query(Customer $customer, string $label): Builder
    {
        $model = AdSpendCredit::PLATFORM_SPEND_MODELS[$label];
        $platform = match ($label) {
            'Google Ads' => 'google', 'Facebook Ads' => 'facebook', default => null,
        };
        $customer->loadMissing('campaigns.strategies');
        $query = $model::query()->whereIn('campaign_id', $customer->campaigns->modelKeys());
        if ($customer->service_type === 'setup_only') {
            return $query->whereRaw('1 = 0');
        }
        if ($platform === null) {
            return $query;
        }
        $field = $platform.'_campaign_id';
        $accountField = $platform.'_account_id';
        $account = $platform === 'google' ? $customer->cleanGoogleCustomerId() : preg_replace('/^act_/', '', (string) $customer->facebook_ads_account_id);
        // Preserve older, unattributed rows for legacy settlements, but never sum a different known account or currency.
        $query->where(fn ($q) => $q->whereNull($accountField)->orWhere($accountField, $account))
            ->where(fn ($q) => $q->whereNull('currency')->orWhere('currency', $customer->billingCurrency()));

        return $query->where(function (Builder $allowed) use ($customer, $platform, $field, $accountField) {
            $allowed->whereRaw('1 = 0');
            foreach ($customer->campaigns as $campaign) {
                $campaign->setRelation('customer', $customer);
                $entries = array_values(array_filter(CampaignResourceInventory::entries($campaign), fn ($entry) => $entry['platform'] === $platform));
                $legacyPaid = $platform !== 'google' || ! $customer->isSelfFundedAds();
                $scoped = $campaign->strategies->contains(fn ($s) => ($s->launch_intent['version'] ?? 1) === 2 && CampaignResourceInventory::platform($s) === $platform);
                if ($entries === [] && $legacyPaid && ! $scoped) {
                    $allowed->orWhere('campaign_id', $campaign->id);
                }
                foreach ($entries as $entry) {
                    $strategy = $campaign->strategies->firstWhere('id', $entry['strategy_id']);
                    if (! $strategy && $scoped) {
                        continue;
                    }
                    $binding = $strategy?->launch_intent['funding'] ?? [];
                    $new = ($strategy?->launch_intent['version'] ?? 1) === 2;
                    if ($new ? (($binding['payer'] ?? '') !== 'agency' || ($binding['account'] ?? null) !== $entry['account_id']
                        || ($binding['currency'] ?? null) !== $customer->billingCurrency()) : ! $legacyPaid) {
                        continue;
                    }
                    $allowed->orWhere(fn ($resource) => $resource->where('campaign_id', $campaign->id)
                        ->when($new, fn ($verified) => $verified->where($accountField, $entry['account_id'])->where('currency', $binding['currency']))
                        ->where(fn ($id) => $id->where($field, $entry['resource_id'])->when(! $new && count($entries) === 1, fn ($fallback) => $fallback->orWhereNull($field))));
                }
            }
        });
    }
}
