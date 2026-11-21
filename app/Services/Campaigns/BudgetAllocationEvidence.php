<?php

namespace App\Services\Campaigns;

use App\Models\Customer;
use App\Services\Conversions\CampaignOutcomeEvidence;
use App\Services\FacebookAds\FacebookAttributionEvidence;
use App\Services\GoogleAds\ConversionTrackingService;
use Illuminate\Support\Facades\DB;

class BudgetAllocationEvidence
{
    /** Compare the same signed goal and funded currency across exact deployed resources. An incomplete arm invalidates the comparison. */
    public function resources(Customer $customer, iterable $campaigns, int $days): array
    {
        $resources = [];
        $goal = null;
        $currency = $customer->billingCurrency();
        $today = now($customer->timezone ?: config('app.timezone'));
        $from = $today->copy()->subDays($days)->toDateString();
        $to = $today->copy()->subDay()->toDateString();
        $seen = [];
        foreach ($campaigns as $campaign) {
            if ((int) $campaign->customer_id !== $customer->id) {
                return [];
            }
            $entries = CampaignResourceInventory::entries($campaign);
            if (($campaign->google_ads_campaign_id && ! collect($entries)->contains('platform', 'google'))
                || ($campaign->facebook_ads_campaign_id && ! collect($entries)->contains('platform', 'facebook'))
                || $campaign->microsoft_ads_campaign_id || $campaign->linkedin_campaign_id) {
                return [];
            }
            foreach ($entries as $entry) {
                $key = implode('/', [$entry['platform'], $entry['account_id'], $entry['resource_id']]);
                $strategy = $campaign->strategies->firstWhere('id', $entry['strategy_id']);
                if (! $strategy || isset($seen[$key])) {
                    return [];
                }
                $seen[$key] = true;
                $binding = app(CampaignOutcomeEvidence::class)->binding($strategy);
                if (! $binding['origin'] || ! $binding['destination'] || ! $binding['goal'] || $binding['account'] !== $entry['account_id']
                    || ($goal !== null && $goal !== $binding['goal'])) {
                    return [];
                }
                $goal = $binding['goal'];
                $platform = $entry['platform'];
                $rows = DB::table($platform.'_ads_performance_data')->where('campaign_id', $campaign->id)->whereBetween('date', [$from, $to])
                    ->where(fn ($q) => $q->where($platform.'_campaign_id', $entry['resource_id'])->orWhereNull($platform.'_campaign_id'))->get();
                if ($rows->isEmpty() || $rows->contains(fn ($r) => $r->strategy_id !== $strategy->id || $r->{$platform.'_account_id'} !== $entry['account_id']
                    || $r->{$platform.'_campaign_id'} !== $entry['resource_id'] || $r->currency !== $currency)) {
                    return [];
                }
                $source = $binding['destination'];
                if ($platform === 'facebook') {
                    $verified = app(FacebookAttributionEvidence::class)->verifiedDays($strategy)->whereBetween('date', [$from, $to])->get();
                    if ($rows->count() !== $verified->count() || ($goal === 'purchase' && $verified->contains(fn ($r) => $r->attributed_conversions > 0 && $r->attributed_value === null))) {
                        return [];
                    }
                    $source = $verified->first()->attribution_window;
                    $outcome = ['conversions' => $verified->sum('attributed_conversions'), 'conversion_value' => $verified->sum('attributed_value')];
                } else {
                    $signed = DB::table('campaign_outcome_events')->where('strategy_id', $strategy->id)->where('binding', $binding['fingerprint'])
                        ->where('goal', $goal)->whereNotNull('provider_receipt')->whereIn('source', ['signed_website', 'native_receipt_replay'])
                        ->whereIn('delivery_status', ['processed', 'verified'])->exists();
                    if (! $signed) {
                        return [];
                    }
                    try {
                        $outcome = app(ConversionTrackingService::class, ['customer' => $customer])->budgetActionTotals($entry['account_id'], $entry['resource_name'],
                            $binding['destination'], $goal, $currency, $from, $to);
                    } catch (\Throwable $e) {
                        report($e);

                        return [];
                    }
                    if ($outcome === null) {
                        return [];
                    }
                }
                $resources[] = [...$entry, ...$outcome, 'campaign' => $campaign, 'budget' => $strategy->daily_budget, 'goal' => $goal,
                    'currency' => $currency, 'binding' => $binding['fingerprint'], 'source' => $source, 'from' => $from, 'through' => $to, 'spend' => $rows->sum('cost'), 'clicks' => $rows->sum('clicks'),
                    'impressions' => $rows->sum('impressions'), 'days' => $rows->count()];
            }
        }

        return $resources;
    }
}
