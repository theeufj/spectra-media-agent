<?php

namespace App\Services\FacebookAds;

use App\Models\Strategy;
use App\Services\Campaigns\CampaignResourceInventory;
use App\Services\Conversions\CampaignOutcomeEvidence;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FacebookAttributionEvidence
{
    /**
     * Insights identify an offsite action, but do not identify its pixel. Bind it to the actual ad set first.
     *
     * @phpstan-impure
     */
    public function observe(Strategy $strategy, array $entry, InsightService $insights): ?object
    {
        $binding = app(CampaignOutcomeEvidence::class)->binding($strategy);
        $windows = DB::table('facebook_attribution_windows')->where('strategy_id', $strategy->id);
        $adset = (string) $strategy->facebook_adset_id;
        if ($binding['channel'] !== 'facebook' || $entry['account_id'] !== $binding['account'] || $entry['strategy_id'] !== $strategy->id
            || ! ctype_digit($adset) || ! $this->signed($strategy, $binding)) {
            $windows->delete();

            return null;
        }

        $account = app(AdAccountService::class, ['customer' => $strategy->campaign->customer])->getAdAccount($entry['account_id']);
        $remote = $insights->attributionAdSet($adset);
        if ($remote === null || $account === null) {
            $windows->update(['verified_at' => null]);
            throw new \RuntimeException('Meta could not return the selected ad set and its reporting account.');
        }
        $zone = $account['timezone_name'] ?? '';
        $currency = $account['currency'] ?? '';
        $event = FacebookCampaignMetrics::pixelEvent($binding['goal']);
        $promoted = $remote['promoted_object'] ?? [];
        $updated = $remote['updated_time'] ?? null;
        if ($event === null || ! is_string($updated) || ! strtotime($updated) || strtotime($updated) > now()->timestamp
            || ($account['id'] ?? '') !== 'act_'.$entry['account_id'] || ! preg_match('/^[A-Z]{3}$/D', $currency)
            || ! in_array($zone, DateTimeZone::listIdentifiers(), true)
            || (string) ($remote['id'] ?? '') !== $adset || (string) ($remote['account_id'] ?? '') !== $entry['account_id']
            || (string) ($remote['campaign_id'] ?? '') !== $entry['resource_id'] || (string) ($promoted['pixel_id'] ?? '') !== (string) $binding['destination']
            || ($promoted['custom_event_type'] ?? null) !== strtoupper($event) || ! empty($promoted['custom_conversion_id'])) {
            $windows->delete();

            return null;
        }

        $identity = ['binding' => $binding['fingerprint'], 'account_id' => $entry['account_id'], 'campaign_id' => $entry['resource_id'],
            'adset_id' => $adset, 'pixel_id' => $binding['destination'], 'goal' => $binding['goal'], 'currency' => $currency, 'timezone' => $zone];
        $signature = hash('sha256', json_encode([$identity, $updated], JSON_THROW_ON_ERROR));
        $previous = $windows->first();
        // Even a change away and back to the same pixel changes updated_time. No historic Insights row identifies that change.
        $same = $previous && $previous->provider_signature === $signature;
        DB::table('facebook_attribution_windows')->updateOrInsert(['strategy_id' => $strategy->id], [
            ...$identity, 'window_id' => $same ? $previous->window_id : (string) Str::uuid(),
            'first_full_day' => $same ? $previous->first_full_day : now($zone)->addDay()->toDateString(),
            'provider_signature' => $signature, 'verified_at' => now(),
        ]);

        return $windows->first();
    }

    public function synchronize(Strategy $strategy, array $entry, InsightService $insights): void
    {
        try {
            $window = $this->observe($strategy, $entry, $insights);
            if (! $window) {
                return;
            }
            $until = CarbonImmutable::now($window->timezone)->subDay();
            $since = max($window->first_full_day, $until->subDays(29)->toDateString());
            $days = $this->daily($window, $insights, $since, $until->toDateString());
            if ($days !== [] && ! $this->stillMatches($window, $insights->attributionAdSet($window->adset_id) ?? [])) {
                throw new \RuntimeException('The selected Meta promoted object changed during its daily report.');
            }
            DB::transaction(function () use ($strategy, $window, $days, $since, $until) {
                $daily = DB::table('facebook_ads_performance_data')->where('strategy_id', $strategy->id)->where('campaign_id', $strategy->campaign_id)
                    ->where('facebook_account_id', $window->account_id)->where('facebook_campaign_id', $window->campaign_id)->where('currency', $window->currency);
                // Insights can revise or remove a day's result. Only the latest complete report supports proof.
                (clone $daily)->whereBetween('date', [$since, $until->toDateString()])->update(['attribution_window' => null, 'attributed_conversions' => null, 'attributed_value' => null]);
                foreach ($days as $date => $data) {
                    (clone $daily)->where('date', $date)->update($data);
                }
            });
        } catch (\Throwable $e) {
            DB::table('facebook_attribution_windows')->where('strategy_id', $strategy->id)->update(['verified_at' => null]);
            throw $e;
        }
    }

    private function signed(Strategy $strategy, array $binding): bool
    {
        return $binding['destination'] && $binding['origin'] && $binding['goal']
            && DB::table('campaign_outcome_events')->where('strategy_id', $strategy->id)->where('binding', $binding['fingerprint'])
                ->where('goal', $binding['goal'])->whereNotNull('provider_receipt')
                ->whereIn('source', ['signed_website', 'native_receipt_replay'])
                ->where(fn ($q) => $q->where(fn ($test) => $test->where('mode', 'test')->where('delivery_status', 'verified'))
                    ->orWhere(fn ($live) => $live->where('mode', 'live')->where('delivery_status', 'accepted')))->exists();
    }

    public function current(Strategy $strategy, array $binding): ?object
    {
        if (! $this->signed($strategy, $binding)) {
            return null;
        }
        $entry = collect(CampaignResourceInventory::entries($strategy->campaign))->firstWhere('strategy_id', $strategy->id);
        if (! $entry || $entry['platform'] !== 'facebook') {
            return null;
        }

        return DB::table('facebook_attribution_windows')->where('strategy_id', $strategy->id)->where('binding', $binding['fingerprint'])
            ->where('account_id', $entry['account_id'])->where('campaign_id', $entry['resource_id'])->where('adset_id', (string) $strategy->facebook_adset_id)
            ->where('verified_at', '>=', now()->subHours(36))->first();
    }

    /** Recheck the live promoted object after reading treatment totals. */
    public function stillMatches(object $window, array $remote): bool
    {
        $identity = [];
        foreach (['binding', 'account_id', 'campaign_id', 'adset_id', 'pixel_id', 'goal', 'currency', 'timezone'] as $field) {
            $identity[$field] = $window->$field;
        }
        $object = $remote['promoted_object'] ?? [];

        return (string) ($remote['id'] ?? '') === $window->adset_id && (string) ($remote['account_id'] ?? '') === $window->account_id
            && (string) ($remote['campaign_id'] ?? '') === $window->campaign_id && (string) ($object['pixel_id'] ?? '') === $window->pixel_id
            && ($object['custom_event_type'] ?? null) === strtoupper((string) FacebookCampaignMetrics::pixelEvent($window->goal)) && empty($object['custom_conversion_id'])
            && $window->provider_signature === hash('sha256', json_encode([$identity, $remote['updated_time'] ?? null], JSON_THROW_ON_ERROR));
    }

    public function matchesRow(object $window, array $row): bool
    {
        return (string) ($row['account_id'] ?? '') === $window->account_id && ($row['account_currency'] ?? '') === $window->currency
            && (string) ($row['campaign_id'] ?? '') === $window->campaign_id && (string) ($row['adset_id'] ?? '') === $window->adset_id;
    }

    public function daily(object $window, InsightService $insights, string $since, string $until): array
    {
        $since = max($since, $window->first_full_day);
        $until = min($until, CarbonImmutable::now($window->timezone)->subDay()->toDateString());
        if ($since > $until) {
            return [];
        }
        $rows = $insights->getAdSetInsights($window->adset_id, $since, $until,
            ['account_id', 'account_currency', 'campaign_id', 'adset_id', 'date_start', 'date_stop', 'actions', 'action_values']);
        if ($rows === null) {
            throw new \RuntimeException('Meta did not return the full daily attribution report.');
        }
        $days = [];
        foreach ($rows as $row) {
            $day = $row['date_start'] ?? '';
            $count = FacebookCampaignMetrics::pixelAmount($row['actions'] ?? [], $window->goal);
            if (! $this->matchesRow($window, $row) || $day !== ($row['date_stop'] ?? '') || $day < $since || $day > $until
                || isset($days[$day]) || $count === null) {
                throw new \RuntimeException('Meta daily attribution rows did not match the requested ad set, currency, event and dates.');
            }
            $value = $window->goal === 'purchase' && isset($row['action_values']) ? FacebookCampaignMetrics::pixelAmount($row['action_values'], 'purchase') : null;
            // A purchase without a matching value is a count with unknown value, not a zero-valued order.
            if ($value === '0' && $count > 0 && ! in_array('offsite_conversion.fb_pixel_purchase', array_column($row['action_values'] ?? [], 'action_type'), true)) {
                $value = null;
            }
            if ($count == 0 && $window->goal === 'purchase') {
                $value = '0';
            }
            $days[$day] = ['attribution_window' => $window->window_id, 'attributed_conversions' => $count, 'attributed_value' => $value];
        }

        return $days;
    }

    /** Canonical daily inputs for reporting and optimization under the current signed binding. */
    public function verifiedDays(Strategy $strategy, ?object $window = null): Builder
    {
        $window ??= $this->current($strategy, app(CampaignOutcomeEvidence::class)->binding($strategy));
        $rows = DB::table('facebook_ads_performance_data')->where('campaign_id', $strategy->campaign_id)->where('strategy_id', $strategy->id);
        if (! $window) {
            return $rows->whereRaw('1 = 0');
        }

        return $rows->where('facebook_campaign_id', $window->campaign_id)->where('facebook_account_id', $window->account_id)->where('currency', $window->currency)
            ->where('attribution_window', $window->window_id)->whereDate('date', '>=', $window->first_full_day)->whereNotNull('attributed_conversions');
    }

    public function summary(Strategy $strategy, array $binding): ?array
    {
        $window = $this->current($strategy, $binding);
        if (! $window) {
            return null;
        }
        $yesterday = CarbonImmutable::now($window->timezone)->subDay();
        $row = $this->verifiedDays($strategy, $window)->whereBetween('date', [$yesterday->subDays(29)->toDateString(), $yesterday->toDateString()])
            ->selectRaw('MIN(date) AS first_day, MAX(date) AS last_day, SUM(attributed_conversions) AS conversions,
                CASE WHEN COUNT(CASE WHEN attributed_conversions > 0 AND attributed_value IS NULL THEN 1 END) = 0 THEN SUM(attributed_value) END AS purchase_value')->first();

        return ['account' => $window->account_id, 'currency' => $window->currency, 'conversions' => $row->conversions ?? null,
            'purchase_value' => $window->goal === 'purchase' ? ($row->purchase_value ?? null) : null, 'from' => $row->first_day ?? null, 'to' => $row->last_day ?? null];
    }
}
