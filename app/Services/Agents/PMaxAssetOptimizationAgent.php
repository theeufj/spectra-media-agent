<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\PerformanceMaxServices\GetAssetGroupPerformance;
use Illuminate\Support\Facades\Log;

/**
 * PMaxAssetOptimizationAgent
 *
 * Identifies low-performing assets for the next approved creative release.
 */
class PMaxAssetOptimizationAgent
{
    protected Customer $customer;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    /**
     * Analyse a PMax campaign's asset groups and flag low performers.
     *
     * @return array{low_detected: int, text_replaced: int, text_flagged: int, image_flagged: int, observed_assets: int, insufficient_data_assets: int, errors: array}
     */
    public function run(Campaign $campaign): array
    {
        $result = [
            'low_detected' => 0,
            'text_replaced' => 0,
            'text_flagged' => 0,
            'image_flagged' => 0,
            'observed_assets' => 0,
            'insufficient_data_assets' => 0,
            'errors' => [],
        ];

        $campaignResourceName = $campaign->googleAdsResourceName();
        if (! $campaignResourceName) {
            Log::warning('PMaxAssetOptimizationAgent: No google_ads_campaign_id on campaign', [
                'campaign_id' => $campaign->id,
            ]);
            $result['errors'][] = 'No google_ads_campaign_id set on campaign';

            return $result;
        }

        $customerId = $this->customer->cleanGoogleCustomerId();
        if (! $customerId) {
            $result['errors'][] = 'No google_ads_customer_id set on customer';

            return $result;
        }

        if ($campaign->customer_id !== $this->customer->id) {
            $result['errors'][] = 'Campaign customer does not match the optimisation account.';

            return $result;
        }
        $reader = app(GetAssetGroupPerformance::class, ['customer' => $this->customer]);
        $report = $reader($customerId, $campaignResourceName);
        $lowAssets = $report['low'];
        $result['low_detected'] = count($lowAssets);
        $result['observed_assets'] = count($report['observed']) + $result['low_detected'];
        $result['insufficient_data_assets'] = count($report['pending']) + count($report['learning']);
        $total = $result['observed_assets'] + $result['insufficient_data_assets'];
        if ($total === 0) {
            return $result;
        }
        $assets = array_merge($report['pending'], $report['learning'], $report['observed'], $lowAssets);
        AgentActivity::record(
            'pmax_asset_optimization', 'asset_performance_observed',
            "Checked {$total} Performance Max assets. {$result['insufficient_data_assets']} have insufficient delivery; {$result['low_detected']} met the account's spend and impression thresholds for review.",
            $this->customer->id, $campaign->id,
            ['campaign_resource' => $campaignResourceName, 'reporting_range' => $report['date_range'],
                'minimum_impressions' => $report['minimum_impressions'], 'observed_assets' => $result['observed_assets'],
                'insufficient_data_assets' => $result['insufficient_data_assets'], 'no_delivery_assets' => count($report['pending']),
                'highest_asset_impressions' => max(array_column($assets, 'impressions')),
                'assets_with_clicks' => count(array_filter(array_column($assets, 'clicks'))),
                'review_candidates' => $lowAssets],
        );

        if (empty($lowAssets)) {
            return $result;
        }

        foreach ($lowAssets as $asset) {
            $fieldType = $asset['field_type'];
            if (in_array($fieldType, ['HEADLINE', 'LONG_HEADLINE', 'DESCRIPTION', 'MARKETING_IMAGE', 'SQUARE_MARKETING_IMAGE', 'PORTRAIT_MARKETING_IMAGE', 'YOUTUBE_VIDEO'], true)) {
                $this->propose($campaign, $campaignResourceName, $asset);
            }

            if (in_array($fieldType, ['HEADLINE', 'LONG_HEADLINE', 'DESCRIPTION'], true)) {
                $result['text_flagged']++;

                continue;
            }

            // Image assets: log warning and record activity for human review
            if (in_array($fieldType, ['MARKETING_IMAGE', 'SQUARE_MARKETING_IMAGE', 'PORTRAIT_MARKETING_IMAGE', 'LOGO', 'LANDSCAPE_LOGO'], true)) {
                $imageUrl = $asset['image_url'] ?? '(unknown)';

                Log::warning('PMaxAssetOptimizationAgent: Image asset met the account thresholds for review', [
                    'field_type' => $fieldType,
                    'image_url' => $imageUrl,
                    'asset_group' => $asset['asset_group'] ?? null,
                    'campaign_id' => $campaign->id,
                ]);

                AgentActivity::record(
                    'pmax_asset_optimization',
                    'image_asset_flagged',
                    "Image asset ({$fieldType}) met the account thresholds for creative review in campaign \"{$campaign->name}\"",
                    $this->customer->id,
                    $campaign->id,
                    [
                        'field_type' => $fieldType,
                        'image_url' => $imageUrl,
                        'asset_resource' => $asset['asset_resource'],
                        'asset_group' => $asset['asset_group'],
                    ]
                );

                $result['image_flagged']++;
            }
        }

        return $result;
    }

    private function propose(Campaign $campaign, string $campaignResource, array $asset): void
    {
        $group = $asset['asset_group'] ?? null;
        $resource = $asset['asset_resource'] ?? null;
        if (! $group || ! $resource) {
            return;
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($campaign, $campaignResource, $group, $resource, $asset) {
            $strategy = $campaign->strategies()->where('campaign_type', 'performance_max')->where('deployment_status', 'active')
                ->where('execution_result->metadata->google_pmax_staging->asset_group', $group)->lockForUpdate()->first();
            if (! $strategy || $strategy->reusableGoogleCampaignId() !== $campaignResource
                || \App\Models\CreativeBrief::where('campaign_id', $campaign->id)->where('brief_type', 'pmax_replacement')
                    ->where('context->asset_resource', $resource)->where('context->asset_group', $group)
                    ->where('context->release', $strategy->creative_approval['fingerprint'] ?? 'legacy')->exists()) {
                return;
            }
            $bindings = $strategy->execution_result['metadata']['google_pmax_staging']['media_bindings'] ?? [];
            $rotating = isset($bindings[$resource]) && app(\App\Services\Creative\ApprovedVariantRotation::class)->propose($strategy, $bindings[$resource], $bindings);
            \App\Models\CreativeBrief::create(['customer_id' => $campaign->customer_id, 'campaign_id' => $campaign->id, 'platform' => 'google',
                'brief_type' => 'pmax_replacement', 'status' => $rotating ? 'actioned' : 'pending', 'actioned_at' => $rotating ? now() : null, 'created_by_agent' => 'pmax_asset_optimization',
                'ai_brief' => 'Prepare a different '.strtolower(str_replace('_', ' ', $asset['field_type'])).' for the approved offer. Preserve the audience, destination and daily budget.',
                'context' => ['strategy_id' => $strategy->id, 'asset_group' => $group, 'asset_resource' => $resource, 'field_type' => $asset['field_type'],
                    'release' => $strategy->creative_approval['fingerprint'] ?? 'legacy', 'impressions' => $asset['impressions'] ?? 0, 'clicks' => $asset['clicks'] ?? 0]]);
        });
    }
}
