<?php

namespace App\Services\Agents\Optimization;

use App\Models\Campaign;
use App\Models\Recommendation;
use App\Services\Campaigns\CampaignResourceInventory;
use App\Services\FacebookAds\AdSetService;
use App\Services\FacebookAds\CampaignService;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use Illuminate\Support\Facades\DB;

/** Receipts prove submission. These reads prove that the intended value is now present on the intended campaign. */
class RecommendationReadback extends ReadCampaignConfiguration
{
    public static function reconcile(Recommendation $recommendation): bool
    {
        $campaign = Campaign::with('customer', 'strategies')->find($recommendation->campaign_id);
        $receipt = $recommendation->execution['result']['receipt'] ?? null;
        $target = $recommendation->target_entity;
        if (! $campaign || ! is_array($target) || ! is_array($receipt) || $receipt === [] || $recommendation->status !== 'pending_verification') {
            return false;
        }
        $type = RecommendationScorer::canonicalType($recommendation->type);
        $entries = CampaignResourceInventory::entries($campaign);
        $matches = array_filter($entries, fn ($entry) => $entry['platform'] === $recommendation->platform
            && $entry['account_id'] === ($target['account_id'] ?? null) && $entry['resource_id'] === (string) ($target['external_campaign_id'] ?? '')
            && $entry['strategy_id'] === ($target['strategy_id'] ?? null));
        if (count($matches) !== 1 || $type === 'BUDGET' && count($entries) !== 1) {
            return false;
        }
        $view = CampaignResourceInventory::view($campaign, reset($matches));
        if (! app(self::class, ['customer' => $campaign->customer])->confirmed($view, $type, $receipt)) {
            return false;
        }

        return DB::transaction(function () use ($recommendation, $campaign, $type, $receipt) {
            $locked = Recommendation::lockForUpdate()->find($recommendation->id);
            if (! $locked || $locked->status !== 'pending_verification' || $locked->execution !== $recommendation->execution) {
                return false;
            }
            $execution = $locked->execution;
            $execution['result'] = array_replace($execution['result'], ['applied' => true, 'pending_verification' => false, 'verified_at' => now()->toIso8601String(),
                'message' => 'The platform confirmed the requested change.']);
            $locked->update(['status' => 'applied', 'execution' => $execution]);
            if ($type === 'BUDGET') {
                $parent = Campaign::lockForUpdate()->findOrFail($campaign->id);
                if (! $parent->last_budget_changed_at || $parent->last_budget_changed_at->lte($locked->created_at)) {
                    $parent->update(['daily_budget' => $receipt['budget_cents'] / 100]);
                }
            }
            if ($type === 'AUDIENCE') {
                \App\Models\Audience::where('customer_id', $campaign->customer_id)->where('campaign_id', $campaign->id)
                    ->where('platform_resource_name', $receipt['audience'])->update(['status' => 'active']);
            }

            return true;
        });
    }

    public function confirmed(Campaign $campaign, string $type, array $receipt): bool
    {
        $account = $campaign->customer?->cleanGoogleCustomerId();
        $resource = $campaign->googleAdsResourceName();
        if ($type === 'AUDIENCE' && $campaign->facebook_ads_campaign_id && ! $campaign->google_ads_campaign_id) {
            $audiences = app(\App\Services\FacebookAds\CustomAudienceService::class, ['customer' => $campaign->customer])->listAudiences((string) $campaign->customer->facebook_ads_account_id);

            return collect($audiences)->contains(fn ($item) => ($item['id'] ?? null) === $receipt['audience'] && ($item['name'] ?? null) === $receipt['name']);
        }
        if ($type === 'BUDGET' && $campaign->facebook_ads_campaign_id && ! $campaign->google_ads_campaign_id) {
            $params = ['customer' => $campaign->customer];
            $id = (string) $campaign->facebook_ads_campaign_id;
            $remote = app(CampaignService::class, $params)->getCampaign($id);
            if (($remote['id'] ?? null) !== $id || ($remote['account_id'] ?? null) !== preg_replace('/^act_/', '', (string) $campaign->customer->facebook_ads_account_id)) {
                return false;
            }
            if ((int) ($remote['daily_budget'] ?? 0) > 0) {
                return (int) $remote['daily_budget'] === $receipt['budget_cents'];
            }
            $sets = app(AdSetService::class, $params)->listAdSets($id);
            if (! $sets || array_any($sets, fn ($set) => empty($set['daily_budget']) || ! empty($set['lifetime_budget']))) {
                return false;
            }

            return array_sum(array_column($sets, 'daily_budget')) === $receipt['budget_cents'];
        }
        if (! $account || $this->customer?->id !== $campaign->customer_id || $account !== $this->customer->cleanGoogleCustomerId()
            || ! $resource || ! preg_match('#^customers/'.preg_quote($account, '#').'/campaigns/\d+$#D', $resource)) {
            return false;
        }
        $where = " WHERE campaign.resource_name = '{$resource}'";
        if ($type === 'BUDGET' || $type === 'NETWORK_SETTINGS') {
            $row = $this->rows($account, 'SELECT campaign.resource_name, campaign.network_settings.target_search_network, campaign.network_settings.target_content_network, campaign_budget.amount_micros, campaign_budget.explicitly_shared FROM campaign'.$where)[0] ?? [];
            if (($row['campaign']['resourceName'] ?? null) !== $resource) {
                return false;
            }
            if ($type === 'BUDGET') {
                return ! ($row['campaignBudget']['explicitlyShared'] ?? false) && (int) ($row['campaignBudget']['amountMicros'] ?? 0) === $receipt['budget_cents'] * 10000;
            }
            $network = $row['campaign']['networkSettings'] ?? null;
            $keys = ['target_search_network' => 'targetSearchNetwork', 'target_content_network' => 'targetContentNetwork'];

            return is_array($network) && ! array_diff_key($receipt['network'], $keys)
                && array_all(array_keys($receipt['network']), fn ($key) => ($network[$keys[$key]] ?? false) === $receipt['network'][$key]);
        }
        if ($type === 'NEGATIVE_KEYWORDS') {
            $rows = $this->rows($account, 'SELECT campaign_criterion.keyword.text, campaign_criterion.keyword.match_type FROM campaign_criterion'.$where." AND campaign_criterion.negative = TRUE AND campaign_criterion.status = 'ENABLED' AND campaign_criterion.type = 'KEYWORD'");
            $words = collect($rows)->pluck('campaignCriterion.keyword')->where('matchType', $receipt['match_type'])->pluck('text')->map(fn ($text) => mb_strtolower($text))->all();

            return array_diff(array_map(mb_strtolower(...), $receipt['keywords']), $words) === [];
        }
        if ($type === 'KEYWORDS' || $type === 'BIDDING') {
            $criterion = $receipt['criterion'] ?? '';
            if (! preg_match('#^customers/'.$account.'/adGroupCriteria/\d+~\d+$#D', $criterion)) {
                return false;
            }
            $row = $this->rows($account, 'SELECT campaign.resource_name, ad_group_criterion.resource_name, ad_group_criterion.status, ad_group_criterion.cpc_bid_micros FROM ad_group_criterion'.$where." AND ad_group_criterion.resource_name = '{$criterion}' AND ad_group_criterion.status IN ('ENABLED', 'PAUSED', 'REMOVED')")[0] ?? [];
            $value = $row['adGroupCriterion'] ?? [];
            if (($row['campaign']['resourceName'] ?? null) !== $resource || ($value['resourceName'] ?? null) !== $criterion) {
                return false;
            }

            return isset($receipt['cpc']) ? (int) ($value['cpcBidMicros'] ?? 0) === $receipt['cpc'] : ($value['status'] ?? null) === ($receipt['status'] ?? '');
        }
        if (in_array($type, ['TARGETING', 'SCHEDULE'], true)) {
            $criterion = $receipt['criterion'] ?? '';
            if (! preg_match('#^customers/'.$account.'/campaignCriteria/\d+~\d+$#D', $criterion)) {
                return false;
            }
            $row = $this->rows($account, 'SELECT campaign_criterion.resource_name, campaign_criterion.bid_modifier FROM campaign_criterion'.$where." AND campaign_criterion.resource_name = '{$criterion}' AND campaign_criterion.status = 'ENABLED'")[0]['campaignCriterion'] ?? [];

            return ($row['resourceName'] ?? null) === $criterion && isset($row['bidModifier']) && abs((float) $row['bidModifier'] - $receipt['modifier']) < 0.0001;
        }
        if ($type === 'AUDIENCE') {
            $audience = $receipt['audience'] ?? '';
            if (! preg_match('#^customers/'.$account.'/userLists/\d+$#D', $audience)) {
                return false;
            }
            $row = $this->rows($account, "SELECT user_list.resource_name, user_list.name FROM user_list WHERE user_list.resource_name = '{$audience}'")[0]['userList'] ?? [];

            return ($row['resourceName'] ?? null) === $audience && ($row['name'] ?? null) === $receipt['name'];
        }
        if ($type === 'AD_EXTENSIONS') {
            $asset = $receipt['asset'] ?? '';
            if (! preg_match('#^customers/'.$account.'/assets/\d+$#D', $asset)) {
                return false;
            }
            $rows = $this->rows($account, 'SELECT campaign_asset.asset, campaign_asset.field_type FROM campaign_asset'.$where." AND campaign_asset.asset = '{$asset}' AND campaign_asset.status = 'ENABLED'");

            return collect($rows)->pluck('campaignAsset')->contains(fn ($link) => $link['asset'] === $asset && ($link['fieldType'] ?? null) === $receipt['role']);
        }

        return false;
    }
}
