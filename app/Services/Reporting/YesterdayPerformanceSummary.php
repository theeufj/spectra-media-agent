<?php

namespace App\Services\Reporting;

use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;
use App\Models\Strategy;
use App\Services\FacebookAds\FacebookAttributionEvidence;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class YesterdayPerformanceSummary
{
    /**
     * Get a cross-platform performance summary for a customer on a given date.
     */
    public function forCustomer(Customer $customer, ?string $date = null): array
    {
        $date = $date ?? Carbon::yesterday()->toDateString();
        $priorDate = Carbon::parse($date)->subDay()->toDateString();

        $campaigns = $customer->campaigns()->get();
        $all = $this->rows($campaigns->pluck('id'), $priorDate, $date);
        $day = $all->where('date', $date);
        $combined = $this->metrics($day);
        $combinedPrior = $this->metrics($all->where('date', $priorDate));
        $breakdown = [];
        foreach ($campaigns as $campaign) {
            $rows = $day->where('campaign_id', $campaign->id);
            if ($rows->isEmpty()) {
                continue;
            }
            $groups = $rows->groupBy('source')->map(fn ($group) => $this->metrics($group));
            $breakdown[] = ['campaign_id' => $campaign->id, 'campaign_name' => $campaign->name,
                'platform' => $groups->count() > 1 ? 'both' : $groups->keys()->first(), 'combined' => $this->metrics($rows), ...$groups->all()];
        }

        return [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'date' => $date,
            'google' => $this->metrics($day->where('source', 'google')),
            'facebook' => $this->metrics($day->where('source', 'facebook')),
            'combined' => $combined,
            'first_party' => $this->firstParty($customer, $date),
            'prior_day' => $combinedPrior,
            'changes' => $this->calculateChanges($combined, $combinedPrior),
            'campaigns' => $breakdown,
        ];
    }

    public function platforms(Customer $customer, string $from, string $to): array
    {
        $rows = $this->rows($customer->campaigns()->pluck('id'), $from, $to);

        return ['google' => $this->metrics($rows->where('source', 'google')), 'facebook' => $this->metrics($rows->where('source', 'facebook'))];
    }

    private function rows(Collection $ids, string $from, string $to): Collection
    {
        $googleRows = GoogleAdsPerformanceData::whereIn('campaign_id', $ids)->whereBetween('date', [$from, $to])
            ->toBase()->get()->each(function ($row) {
                $row->source = 'google';
            });
        $metaRows = FacebookAdsPerformanceData::whereIn('campaign_id', $ids)->whereBetween('date', [$from, $to])->toBase()->get();
        $verified = [];
        $evidence = app(FacebookAttributionEvidence::class);
        foreach (Strategy::with(['campaign.customer', 'campaign.strategies'])->whereIn('id', $metaRows->pluck('strategy_id')->filter()->unique())->get() as $strategy) {
            foreach ($evidence->verifiedDays($strategy)->whereBetween('date', [$from, $to])->pluck('id') as $id) {
                $verified[$id] = true;
            }
        }
        foreach ($metaRows as $row) {
            $row->source = 'facebook';
            $row->conversions = isset($verified[$row->id]) ? $row->attributed_conversions : null;
            $row->conversion_value = isset($verified[$row->id]) ? $row->attributed_value : null;
        }

        return $googleRows->concat($metaRows);
    }

    private function firstParty(Customer $customer, string $date): array
    {
        $from = Carbon::parse($date, $customer->timezone ?: config('app.timezone'))->startOfDay();
        $through = $from->copy()->addDay();
        // One merchant source ID can be sent to both advertising platforms.
        $signed = \Illuminate\Support\Facades\DB::table('campaign_outcome_events as e')
            ->join('strategies as s', 's.id', '=', 'e.strategy_id')->join('campaigns as c', 'c.id', '=', 's.campaign_id')
            ->where('c.customer_id', $customer->id)->where('e.mode', 'live')
            ->where('e.occurred_at', '>=', $from->toIso8601String())->where('e.occurred_at', '<', $through->toIso8601String())
            ->select('e.goal', 'e.source_key', 'e.amount', 'e.currency')->distinct()->get();
        $orders = app(\App\Services\Conversions\MerchantOrders::class)->summary($customer, $from, $through);
        $money = [];
        foreach ($orders as $currency => $values) {
            $money[$currency] = intdiv($values['gross_cents'], 100).'.'.str_pad((string) ($values['gross_cents'] % 100), 2, '0', STR_PAD_LEFT);
        }
        $crm = \App\Models\OfflineConversion::where('customer_id', $customer->id)->whereNotNull('source_key')
            ->where('conversion_time', '>=', $from)->where('conversion_time', '<', $through)->count();

        return ['signups' => $signed->where('goal', 'signup')->count(), 'leads' => $signed->where('goal', 'lead')->count(), 'purchases' => array_sum(array_column($orders, 'orders')),
            'orders_by_currency' => $orders,
            'value_by_currency' => $money, 'crm_deals' => $crm];
    }

    private function metrics(Collection $rows): array
    {
        $impressions = (int) $rows->sum('impressions');
        $clicks = (int) $rows->sum('clicks');
        $known = $rows->whereNotNull('conversions');
        $conversions = $known->count() === $rows->count() ? round((float) $known->sum('conversions'), 2) : null;
        $money = [];
        $unpriced = false;
        foreach ($rows as $row) {
            if (! $row->currency || ! preg_match('/^[A-Z]{3}$/D', $row->currency)) {
                $unpriced = true;

                continue;
            }
            $unit = $row->currency;
            $money[$unit] ??= ['spend' => '0', 'conversion_value' => '0'];
            $money[$unit]['spend'] = (string) BigDecimal::of($money[$unit]['spend'])->plus((string) $row->cost);
            if ($row->conversion_value === null || $money[$unit]['conversion_value'] === null) {
                $money[$unit]['conversion_value'] = null;
            } else {
                $money[$unit]['conversion_value'] = (string) BigDecimal::of($money[$unit]['conversion_value'])->plus((string) $row->conversion_value);
            }
        }
        ksort($money);
        $currency = count($money) === 1 && ! $unpriced ? array_key_first($money) : null;
        $spend = $currency ? (float) $money[$currency]['spend'] : ($rows->isEmpty() ? 0 : null);
        $value = $currency && isset($money[$currency]['conversion_value']) ? (float) $money[$currency]['conversion_value'] : null;

        return [
            'impressions' => $impressions, 'clicks' => $clicks, 'currency' => $currency, 'money_by_currency' => $money,
            'spend' => $spend, 'conversion_value' => $value, 'conversions' => $conversions, 'currency_unverified' => $unpriced,
            'confirmed_conversions' => round((float) $known->sum('conversions'), 2),
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0,
            'cpc' => $clicks > 0 && $spend !== null ? round($spend / $clicks, 2) : null,
            'cpa' => $conversions > 0 && $spend !== null ? round($spend / $conversions, 2) : null,
            'roas' => $spend > 0 && $value !== null ? round($value / $spend, 2) : null,
        ];
    }

    private function calculateChanges(array $current, array $prior): array
    {
        $changes = [];
        foreach (['impressions', 'clicks', 'spend', 'conversions', 'ctr', 'cpc', 'cpa'] as $metric) {
            $now = $current[$metric];
            $then = $prior[$metric];
            if ($now === null || $then === null || (in_array($metric, ['spend', 'cpc', 'cpa'], true) && $current['currency'] !== $prior['currency'])) {
                $changes[$metric] = null;

                continue;
            }
            $changes[$metric] = $then > 0 ? round(($now - $then) / $then * 100, 1) : ($now > 0 ? 100.0 : 0.0);
        }

        return $changes;
    }
}
