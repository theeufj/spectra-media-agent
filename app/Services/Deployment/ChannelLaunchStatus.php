<?php

namespace App\Services\Deployment;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\Campaigns\CampaignResourceInventory;
use App\Services\Conversions\CampaignOutcomeEvidence;
use Illuminate\Support\Facades\DB;

/** Reads persisted evidence only. Polling a status screen must never qualify accounts or charge cards. */
class ChannelLaunchStatus
{
    public function forStrategy(Campaign $campaign, Strategy $strategy, array $delivery = []): ?array
    {
        $platform = CampaignResourceInventory::platform($strategy);
        if (! $platform || ! $campaign->customer) {
            return null;
        }
        $intent = $strategy->launch_intent ?? [];
        $resources = collect(CampaignResourceInventory::entries($campaign))->where('strategy_id', $strategy->id)->values();
        $first = $resources->isEmpty() ? null : DB::table($platform === 'google' ? 'google_ads_performance_data' : 'facebook_ads_performance_data')
            ->where('campaign_id', $campaign->id)->whereIn($platform.'_campaign_id', $resources->pluck('resource_id'))
            ->where('impressions', '>', 0)->min('date');
        $hold = empty($intent['id']) ? null : DB::table('launch_funding_reservations')
            ->where('strategy_id', $strategy->id)->where('launch_id', $intent['id'])->first();
        $proof = $intent['funding'] ?? [];
        $account = $hold->account_id ?? $proof['account'] ?? ($platform === 'google' ? $campaign->customer->cleanGoogleCustomerId() :
            preg_replace('/^act_/', '', (string) $campaign->customer->facebook_ads_account_id));
        $qualification = DB::table('channel_account_readiness')->where('customer_id', $campaign->customer_id)
            ->where('platform', $platform)->where('account_id', (string) $account)->first();
        $publication = $strategy->creative_publication ?? [];
        $outcomes = app(CampaignOutcomeEvidence::class)->forStrategy($strategy);
        $outcomes['installation_status'] = $campaign->customer->gtm_installed && $campaign->customer->gtm_last_verified
            ? 'container_observed' : (($outcomes['verified_events'] || ($outcomes['requires_verification'] && $outcomes['verified_for_launch'])) ? 'event_integration_observed' : 'awaiting_verification');
        $issue = $strategy->deployment_error ?: ($qualification->next_action ?? null);
        $owner = null;
        if ($issue) {
            $owner = $proof['owner'] ?? $qualification->owner ?? match ($proof['code'] ?? '') {
                'facebook_page_or_account_missing', 'payment_method_required', 'requires_action' => 'Customer',
                default => 'Site to Spend',
            };
        } elseif (! $outcomes['verified_for_launch'] && $strategy->signed_off_at) {
            $issue = $outcomes['issues'][0]['message'] ?? 'Verify the selected conversion on the website.';
            $owner = 'Website owner';
        }
        $url = $proof['setup_url'] ?? ($owner === 'Website owner' ? $outcomes['setup_url'] : null);
        $path = is_string($url) && (str_starts_with($url, rtrim(config('app.url'), '/').'/') || str_starts_with($url, '/'))
            ? parse_url($url, PHP_URL_PATH) : null;

        return [
            'setup_url' => is_string($path) && preg_match('~^/(?!/)[a-zA-Z0-9/_-]+$~D', $path) ? $path : null,
            'blocker_code' => $owner === 'Website owner' ? 'conversion_test_required' : ($proof['code'] ?? $qualification->blocker ?? null),
            'platform' => $platform, 'resources' => $resources->pluck('resource_id')->all(),
            'account_id' => $account ?: null,
            'payer' => $hold->payer ?? $proof['payer'] ?? $qualification->payer ?? null,
            'currency' => $hold->currency ?? $proof['currency'] ?? $campaign->customer->billingCurrency(),
            'daily_budget' => isset($intent['daily_cents']) ? bcdiv((string) $intent['daily_cents'], '100', 2) : ($strategy->signed_off_at ? $strategy->daily_budget : null),
            'reserved_cents' => $hold ? (int) $hold->held_cents : 0,
            'funding_status' => $hold->status ?? $qualification->blocker ?? $proof['code'] ?? ($qualification->funding ?? 'awaiting_qualification'),
            'creative_version' => $publication['fingerprint'] ?? $strategy->creative_approval['fingerprint'] ?? null,
            'creative_phase' => $publication['phase'] ?? (! empty($strategy->creative_approval['fingerprint']) ? ($resources->isEmpty() ? 'approved' : 'submitted') : null),
            'has_replacement' => $publication !== [],
            'submitted' => ! empty($publication['replacement_links']) || $resources->isNotEmpty(),
            'replacement_eligible' => ($publication['phase'] ?? '') === 'published',
            'ad_strength' => $publication !== [] ? ($publication['ad_strength'] ?? null) : ($delivery['ad_strength'] ?? null),
            'permissions' => $qualification->permissions ?? null, 'checked_at' => $qualification->checked_at ?? null,
            'tracking_installation' => $qualification->tracking ?? null,
            'first_impression_date' => $first,
            'event' => $outcomes, 'next_action' => $issue, 'owner' => $owner,
        ];
    }
}
