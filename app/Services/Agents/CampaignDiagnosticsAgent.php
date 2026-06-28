<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Setting;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Illuminate\Support\Facades\Log;

/**
 * Comprehensive campaign diagnosis agent.
 *
 * Detects structural and strategic issues that existing health checks miss:
 *   - Conversion starvation (spend with zero conversions after adequate ramp time)
 *   - PMax structural gaps (no audience signals, subpage landing URLs)
 *   - Display-only traffic (no search-intent impressions)
 *   - Missing conversion tracking labels
 *
 * Returns structured findings. Never mutates anything — remediation is
 * handled by CampaignRemediationAgent.
 */
class CampaignDiagnosticsAgent
{
    const STARVATION_MIN_SPEND            = 50.0;
    const STARVATION_MIN_DAYS             = 14;
    const DISPLAY_ONLY_MIN_IMPRESSIONS    = 5000;
    const PERFORMANCE_MAX_CHANNEL_TYPE    = 10;

    /**
     * Informational subpaths that should never be a paid campaign landing page.
     * Traffic landing here will bounce before converting.
     */
    const BAD_LANDING_PATHS = [
        '/how-it-works', '/about', '/blog', '/faq', '/features',
        '/docs', '/learn', '/resources', '/help', '/support',
        '/team', '/careers', '/press', '/privacy', '/terms',
    ];

    /**
     * Diagnose a campaign and return all findings.
     *
     * @return array<int, array{type: string, severity: string, platform: string, message: string, details: array, can_auto_fix: bool, recommended_action: string}>
     */
    public function diagnose(Campaign $campaign): array
    {
        $findings = [];

        // Platform-agnostic: conversion label setup
        $findings = array_merge($findings, $this->checkConversionTracking());

        if ($campaign->google_ads_campaign_id && $campaign->customer?->google_ads_customer_id) {
            $findings = array_merge($findings, $this->diagnoseGoogleAds($campaign));
        }

        return $findings;
    }

    // ─── Google Ads ───────────────────────────────────────────────────────────

    private function diagnoseGoogleAds(Campaign $campaign): array
    {
        $findings = [];

        try {
            $perf = $this->fetchGooglePerformance($campaign);

            if ($finding = $this->checkConversionStarvation($campaign, $perf)) {
                $findings[] = $finding;
            }

            if ($perf['channel_type'] === self::PERFORMANCE_MAX_CHANNEL_TYPE) {
                $findings = array_merge($findings, $this->diagnosePMax($campaign));
            }

            if ($finding = $this->checkDisplayOnlyTraffic($campaign, $perf)) {
                $findings[] = $finding;
            }
        } catch (\Exception $e) {
            Log::error('CampaignDiagnosticsAgent: diagnoseGoogleAds failed', [
                'campaign_id' => $campaign->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return $findings;
    }

    private function fetchGooglePerformance(Campaign $campaign): array
    {
        $customer   = $campaign->customer;
        $customerId = $customer->cleanGoogleCustomerId();

        preg_match('/campaigns\/(\d+)$/', $campaign->google_ads_campaign_id, $m);
        $campaignId = $m[1] ?? $campaign->google_ads_campaign_id;

        $service = new class($customer) extends BaseGoogleAdsService {
            public function fetch(string $customerId, string $campaignId): array
            {
                $this->ensureClient();

                $since = now()->subDays(30)->toDateString();
                $today = now()->toDateString();

                $resp = $this->searchQuery(
                    $customerId,
                    "SELECT campaign.advertising_channel_type, campaign.bidding_strategy_type,
                             metrics.cost_micros, metrics.clicks, metrics.conversions, metrics.impressions
                     FROM campaign
                     WHERE campaign.id = {$campaignId}
                     AND segments.date BETWEEN '{$since}' AND '{$today}'"
                );

                $result = [
                    'channel_type'     => 0,
                    'bidding_strategy' => 0,
                    'spend'            => 0.0,
                    'clicks'           => 0,
                    'conversions'      => 0.0,
                    'impressions'      => 0,
                ];

                foreach ($resp->getIterator() as $row) {
                    $c = $row->getCampaign();
                    $met = $row->getMetrics();

                    $result['channel_type']     = $c->getAdvertisingChannelType();
                    $result['bidding_strategy'] = $c->getBiddingStrategyType();
                    $result['spend']            += $met->getCostMicros() / 1_000_000;
                    $result['clicks']           += $met->getClicks();
                    $result['conversions']      += $met->getConversions();
                    $result['impressions']      += $met->getImpressions();
                }

                return $result;
            }
        };

        return $service->fetch($customerId, $campaignId);
    }

    private function checkConversionStarvation(Campaign $campaign, array $perf): ?array
    {
        $deployedAt = $campaign->strategies()
            ->whereIn('deployment_status', ['deployed', 'verified'])
            ->whereNotNull('deployed_at')
            ->min('deployed_at');

        if (!$deployedAt || now()->diffInDays($deployedAt) < self::STARVATION_MIN_DAYS) {
            return null;
        }

        if ($perf['spend'] < self::STARVATION_MIN_SPEND || $perf['conversions'] > 0) {
            return null;
        }

        return [
            'type'     => 'conversion_starvation',
            'severity' => 'critical',
            'platform' => 'google_ads',
            'message'  => sprintf(
                'Campaign has spent $%s over 30 days with zero conversions — the bidding algorithm is flying blind',
                number_format($perf['spend'], 2)
            ),
            'details'           => $perf,
            'can_auto_fix'      => true,
            'auto_fix_action'   => 'refresh_creative',
            'recommended_action' => 'Refresh ad copy and images to give the algorithm new signals; verify conversion tracking is firing',
        ];
    }

    private function diagnosePMax(Campaign $campaign): array
    {
        $findings   = [];
        $customer   = $campaign->customer;
        $customerId = $customer->cleanGoogleCustomerId();

        preg_match('/campaigns\/(\d+)$/', $campaign->google_ads_campaign_id, $m);
        $campaignId = $m[1] ?? $campaign->google_ads_campaign_id;

        $service = new class($customer) extends BaseGoogleAdsService {
            public function inspect(string $customerId, string $campaignId): array
            {
                $this->ensureClient();

                $signalCount = 0;
                try {
                    $resp = $this->searchQuery($customerId,
                        "SELECT asset_group_signal.asset_group FROM asset_group_signal
                         WHERE campaign.id = {$campaignId} LIMIT 1"
                    );
                    foreach ($resp->getIterator() as $_) {
                        $signalCount++;
                    }
                } catch (\Exception) {}

                $landingUrls = [];
                $assetGroups = [];

                $resp = $this->searchQuery($customerId,
                    "SELECT asset_group.resource_name, asset_group.name, asset_group.final_urls
                     FROM asset_group WHERE campaign.id = {$campaignId}"
                );
                foreach ($resp->getIterator() as $row) {
                    $ag = $row->getAssetGroup();
                    $assetGroups[] = [
                        'resource_name' => $ag->getResourceName(),
                        'name'          => $ag->getName(),
                    ];
                    foreach ($ag->getFinalUrls() as $url) {
                        $landingUrls[] = $url;
                    }
                }

                return [
                    'signal_count' => $signalCount,
                    'landing_urls' => array_unique($landingUrls),
                    'asset_groups' => $assetGroups,
                ];
            }
        };

        try {
            $data = $service->inspect($customerId, $campaignId);

            if ($data['signal_count'] === 0) {
                $findings[] = [
                    'type'     => 'pmax_no_audience_signals',
                    'severity' => 'high',
                    'platform' => 'google_ads',
                    'message'  => 'PMax campaign has no audience signals — Google has zero guidance on who to target, so it is spending on broad, unqualified audiences',
                    'details'  => ['campaign_id' => $campaignId, 'asset_groups' => $data['asset_groups']],
                    'can_auto_fix'      => true,
                    'auto_fix_action'   => 'add_audience_signals',
                    'recommended_action' => 'Add search-theme and in-market audience signals so PMax knows who to target',
                ];
            }

            foreach ($data['landing_urls'] as $url) {
                $path = rtrim(parse_url($url, PHP_URL_PATH) ?? '/', '/');
                foreach (self::BAD_LANDING_PATHS as $bad) {
                    if (str_starts_with($path, $bad)) {
                        $findings[] = [
                            'type'     => 'pmax_bad_landing_page',
                            'severity' => 'high',
                            'platform' => 'google_ads',
                            'message'  => "PMax is sending paid traffic to an informational page ({$path}) — visitors arrive to read, not to convert",
                            'details'  => [
                                'current_url'  => $url,
                                'path'         => $path,
                                'asset_groups' => $data['asset_groups'],
                                'website'      => $campaign->customer?->website,
                            ],
                            'can_auto_fix'      => true,
                            'auto_fix_action'   => 'fix_landing_page',
                            'recommended_action' => 'Scan sitemap and update asset group Final URL to the best conversion-focused page',
                        ];
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('CampaignDiagnosticsAgent: PMax inspection failed', [
                'campaign_id' => $campaign->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return $findings;
    }

    private function checkDisplayOnlyTraffic(Campaign $campaign, array $perf): ?array
    {
        if ($perf['impressions'] < self::DISPLAY_ONLY_MIN_IMPRESSIONS) {
            return null;
        }

        $customer   = $campaign->customer;
        $customerId = $customer->cleanGoogleCustomerId();

        preg_match('/campaigns\/(\d+)$/', $campaign->google_ads_campaign_id, $m);
        $campaignId = $m[1] ?? $campaign->google_ads_campaign_id;

        try {
            $service = new class($customer) extends BaseGoogleAdsService {
                public function hasSearchTerms(string $customerId, string $campaignId): bool
                {
                    $this->ensureClient();
                    $since = now()->subDays(30)->toDateString();
                    $today = now()->toDateString();

                    try {
                        $resp = $this->searchQuery($customerId,
                            "SELECT search_term_view.search_term FROM search_term_view
                             WHERE campaign.id = {$campaignId}
                             AND segments.date BETWEEN '{$since}' AND '{$today}'
                             LIMIT 1"
                        );
                        foreach ($resp->getIterator() as $_) {
                            return true;
                        }
                    } catch (\Exception) {}

                    return false;
                }
            };

            if (!$service->hasSearchTerms($customerId, $campaignId)) {
                preg_match('/campaigns\/(\d+)$/', $campaign->google_ads_campaign_id, $m2);
                $cId = $m2[1] ?? $campaign->google_ads_campaign_id;
                $assetGroups = $this->fetchAssetGroups($customer, $customerId, $cId);

                return [
                    'type'     => 'display_only_traffic',
                    'severity' => 'medium',
                    'platform' => 'google_ads',
                    'message'  => number_format($perf['impressions']) . ' impressions with zero search-intent traffic — all clicks are from Display/Discovery, not Search',
                    'details'  => ['impressions' => $perf['impressions'], 'clicks' => $perf['clicks'], 'asset_groups' => $assetGroups],
                    'can_auto_fix'      => true,
                    'auto_fix_action'   => 'add_audience_signals',
                    'recommended_action' => 'Add search-theme signals to guide PMax towards search-intent traffic; consider a companion Search campaign for sustained coverage',
                ];
            }
        } catch (\Exception $e) {
            Log::debug('CampaignDiagnosticsAgent: Traffic quality check failed', [
                'campaign_id' => $campaign->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function fetchAssetGroups(mixed $customer, string $customerId, string $campaignId): array
    {
        try {
            $service = new class($customer) extends BaseGoogleAdsService {
                public function get(string $customerId, string $campaignId): array
                {
                    $this->ensureClient();
                    $groups = [];
                    $resp   = $this->searchQuery($customerId,
                        "SELECT asset_group.resource_name, asset_group.name FROM asset_group WHERE campaign.id = {$campaignId}"
                    );
                    foreach ($resp->getIterator() as $row) {
                        $ag       = $row->getAssetGroup();
                        $groups[] = ['resource_name' => $ag->getResourceName(), 'name' => $ag->getName()];
                    }
                    return $groups;
                }
            };
            return $service->get($customerId, $campaignId);
        } catch (\Exception) {
            return [];
        }
    }

    // ─── Platform-agnostic ───────────────────────────────────────────────────

    private function checkConversionTracking(): array
    {
        $required = ['signup', 'try_now', 'pricing_visit', 'sandbox_launched'];
        $missing  = array_values(array_filter($required, fn($k) => !Setting::get("conversion_label.{$k}")));

        if (empty($missing)) {
            return [];
        }

        return [[
            'type'     => 'conversion_labels_missing',
            'severity' => 'critical',
            'platform' => 'platform',
            'message'  => 'Conversion labels not provisioned for: ' . implode(', ', $missing) . ' — these events are completely invisible to Google Ads',
            'details'  => ['missing' => $missing],
            'can_auto_fix'       => true,
            'auto_fix_action'    => 'provision_conversions',
            'recommended_action' => 'Run `php artisan conversions:provision` to restore missing conversion action labels',
        ]];
    }
}
