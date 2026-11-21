<?php

namespace App\Services\Conversions;

use App\Models\AttributionConversion;
use App\Models\Setting;
use App\Models\Strategy;
use App\Services\FacebookAds\FacebookAttributionEvidence;
use App\Services\FacebookAds\FacebookCampaignMetrics;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use Illuminate\Support\Facades\DB;

class CampaignOutcomeEvidence
{
    public function binding(Strategy $strategy): array
    {
        $customer = $strategy->campaign->customer;
        $platform = strtolower($strategy->platform ?? '');
        $channel = preg_match('/facebook|instagram|meta/', $platform) ? 'facebook' : (preg_match('/google|performance max|pmax/', $platform) ? 'google' : null);
        $category = ReconcileCampaignConversionGoals::categoryFor($strategy);
        $goal = match ($category) {
            'PURCHASE' => 'purchase', 'SIGNUP' => 'signup', 'SUBMIT_LEAD_FORM' => 'lead', default => null,
        };
        if (! $goal && in_array(strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($strategy->conversion_goals['primary_goal'] ?? ''))), ['websitesignup', 'completeregistration', 'registration'])) {
            $goal = 'signup';
        }
        if ($channel === 'facebook') {
            $goal = FacebookCampaignMetrics::declaredGoal($strategy);
        }
        $origin = self::origin($customer->website);
        $landing = $strategy->bidding_strategy['landing_page_url'] ?? $strategy->campaign->landing_page_url ?? $customer->website;
        $account = $destination = null;
        if ($channel === 'facebook') {
            $account = preg_replace('/^act_/', '', (string) $customer->facebook_ads_account_id);
            $destination = $customer->facebook_pixel_id;
            if (! ctype_digit((string) $account) || ! ctype_digit((string) $destination)) {
                $destination = null;
            }
        } elseif ($channel === 'google') {
            $account = $customer->cleanGoogleCustomerId();
            $destination = $strategy->conversion_goals['action_resource'] ?? $strategy->conversion_goals['conversion_action_resource']
                ?? $strategy->conversion_goals['conversion_action_id'] ?? $strategy->bidding_strategy['pmax_conversion_goal']['action_resource']
                ?? $strategy->execution_result['metadata']['google_search_baseline']['conversion_goal']['action_resource'] ?? null;
            if (! $destination && $account === (string) config('conversions.google_ads_customer_id') && $origin === 'https://sitetospend.com') {
                $destination = match ($goal) {
                    'signup' => Setting::get('conversion_resource_name.signup_import'),
                    'purchase' => Setting::get('conversion_resource_name.paid_subscription'),
                    default => null,
                };
            }
            if (ctype_digit((string) $destination)) {
                $destination = "customers/{$account}/conversionActions/{$destination}";
            }
            if (! ctype_digit((string) $account) || ! preg_match('#^customers/'.$account.'/conversionActions/[0-9]+$#D', (string) $destination)) {
                $destination = null;
            }
        }
        $trigger = $strategy->execution_result['metadata']['setup_conversion_goal']['goal'] ?? [];
        $binding = compact('channel', 'account', 'goal', 'destination', 'origin', 'trigger', 'landing');
        $binding['fingerprint'] = hash('sha256', json_encode(['verified_website_origin_v1', $strategy->id, $customer->id, $binding], JSON_THROW_ON_ERROR));

        return $binding;
    }

    public static function origin(?string $url): ?string
    {
        $parts = parse_url((string) $url);
        if (! is_array($parts) || empty($parts['host']) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return 'https://'.strtolower($parts['host']).(isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '');
    }

    public function forStrategy(Strategy $strategy): array
    {
        $binding = $this->binding($strategy);
        $requires = $binding['channel'] !== null && in_array($binding['goal'], ['purchase', 'signup'], true);
        $configured = (bool) ($binding['destination'] && $binding['origin'] && $binding['goal']);
        $query = DB::table('campaign_outcome_events')->where('strategy_id', $strategy->id)->where('binding', $binding['fingerprint']);
        $tested = (clone $query)->where('mode', 'test')->where('updated_at', '>=', now()->subDays(30))->where('delivery_status', 'verified')->whereNotNull('provider_receipt')->exists();
        $live = (clone $query)->where('mode', 'live');
        if (! $tested && $binding['channel'] === 'google') {
            // The same real business receipt can be replayed in the isolated validator.
            // Require the matching import to have actually completed at the pinned destination.
            $tested = (clone $query)->where('mode', 'test')->where('delivery_status', 'validated_only')
                ->where('updated_at', '>=', now()->subDays(30))->whereExists(function ($query) {
                    $query->selectRaw('1')->from('campaign_outcome_events as receipt')->where('receipt.mode', 'live')->where('receipt.delivery_status', 'processed')
                        ->whereNotNull('receipt.provider_receipt')->whereColumn('receipt.strategy_id', 'campaign_outcome_events.strategy_id')
                        ->whereColumn('receipt.binding', 'campaign_outcome_events.binding')->whereColumn('receipt.source_key', 'campaign_outcome_events.source_key')
                        ->whereColumn('receipt.occurred_at', 'campaign_outcome_events.occurred_at')
                        ->whereRaw('receipt.amount IS NOT DISTINCT FROM campaign_outcome_events.amount AND receipt.currency IS NOT DISTINCT FROM campaign_outcome_events.currency');
                })
                ->exists();
        }
        $count = (clone $live)->count();
        $byCurrency = (clone $live)->whereNotNull('currency')->groupBy('currency')->selectRaw('currency, SUM(amount) amount')->pluck('amount', 'currency')->all();
        $latest = (clone $live)->orderByDesc('occurred_at')->value('delivery_status');
        $browser = $binding['goal'] ? AttributionConversion::where('customer_id', $strategy->campaign->customer_id)
            ->where('conversion_type', $binding['goal'])->where('created_at', '>=', now()->subDays(30))->count() : 0;
        $tracking = $strategy->execution_result['metadata']['conversion_tracking_health'] ?? [];
        $reported = $binding['channel'] === 'google' && $configured && ($tracking['status'] ?? '') === 'conversions_observed'
            && ($tracking['intent']['action_resource'] ?? null) === $binding['destination'];
        $meta = $binding['channel'] === 'facebook' && $configured ? app(FacebookAttributionEvidence::class)->summary($strategy, $binding) : null;
        if ($meta !== null) {
            $reported = ($meta['conversions'] ?? 0) > 0;
        }
        $originVerified = (bool) $binding['origin'] && (app(AdvertiserOutcomeOrigin::class)->verified($strategy->campaign->customer, $binding['origin'])
            || app(NativeOutcomeVerification::class)->owns($strategy, $binding));
        $tested = $tested && $originVerified;
        $issues = $requires && ! $tested ? [['code' => 'exact_conversion_test_required', 'message' => 'Verify a successful '.$binding['goal'].' on the advertiser website against the selected advertising destination before spending.']] : [];

        return [
            'setup_url' => route('campaigns.outcome.show', ['campaign' => $strategy->campaign, 'strategy' => $strategy->uuid]),
            'goal' => $binding['goal'], 'requires_verification' => $requires, 'verified_for_launch' => ! $requires || ($configured && $tested),
            'website_origin_verified' => $originVerified, 'binding_status' => $configured ? 'configured' : 'unconfigured', 'verified_events' => $count, 'browser_events' => $browser,
            'verified_value_by_currency' => $byCurrency, 'source_status' => $count ? 'first_party_observed' : ($browser ? 'browser_observed' : 'awaiting_first_party_event'),
            'delivery_status' => $latest ?? 'awaiting_event', 'provider_metrics' => $meta, 'provider_attribution' => $reported ? 'reported' : 'unverified', 'issues' => $issues,
        ];
    }
}
