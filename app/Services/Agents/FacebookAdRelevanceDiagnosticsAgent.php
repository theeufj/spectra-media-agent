<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\FacebookAds\AdService;
use App\Services\FacebookAds\AdSetService;
use Illuminate\Support\Facades\Log;

class FacebookAdRelevanceDiagnosticsAgent
{
    public function analyze(Campaign $campaign): array
    {
        $customer = $campaign->customer;

        if (! $campaign->facebook_ads_campaign_id || ! $customer?->facebook_ads_account_id) {
            return ['skipped' => true];
        }

        // Don't touch campaigns in learning phase
        if (FacebookLearningPhaseAgent::isOnHold($campaign)) {
            return ['skipped' => true, 'reason' => 'learning_phase_hold'];
        }

        $adSetService = new AdSetService($customer);
        $adService = new AdService($customer);

        $adSets = $adSetService->listAdSets($campaign->facebook_ads_campaign_id) ?? [];
        $actions = [];
        $flagged = [];
        $errors = [];

        foreach ($adSets as $adSet) {
            $ads = $adService->listAdsWithDiagnostics($adSet['id'] ?? '') ?? [];

            foreach ($ads as $ad) {
                try {
                    $this->diagnoseAd($campaign, $customer, $ad, $actions, $flagged);
                } catch (\Throwable $e) {
                    report($e);
                    $errors[] = "Error processing ad '{$ad['name']}': ".$e->getMessage();
                    Log::warning('FacebookAdRelevanceDiagnosticsAgent: '.$e->getMessage());
                }
            }
        }

        if ($actions || $flagged) {
            AgentActivity::record(
                'facebook_relevance',
                'relevance_diagnostics_reviewed',
                'Reviewed '.count($flagged)." relevance issue(s) for \"{$campaign->name}\"",
                $campaign->customer_id,
                $campaign->id,
                ['actions' => $actions, 'paused' => [], 'flagged' => $flagged, 'errors' => $errors]
            );
        }

        return [
            'actions' => $actions,
            'paused' => [],
            'flagged' => $flagged,
            'errors' => $errors,
        ];
    }

    private function diagnoseAd(Campaign $campaign, \App\Models\Customer $customer, array $ad, array &$actions, array &$flagged): void
    {
        $adId = $ad['id'] ?? null;
        $adName = $ad['name'] ?? $adId;
        $qualityRank = $ad['quality_ranking'] ?? 'UNKNOWN';
        $engageRank = $ad['engagement_rate_ranking'] ?? 'UNKNOWN';
        $cvRank = $ad['conversion_rate_ranking'] ?? 'UNKNOWN';

        if ($qualityRank === 'BELOW_AVERAGE' && $adId) {
            $staged = app(\App\Services\Creative\ApprovedVariantRotation::class)->proposeMeta($campaign, (string) $adId);
            if ($staged) {
                $actions[] = ['ad_id' => $adId, 'action' => 'replacement_staged'];
            }
            $flagged[] = ['ad' => $adName, 'ad_id' => $adId, 'issue' => 'below_average_quality',
                'recommendation' => $staged ? 'An approved replacement is staged for Meta review.' : 'Review a new creative variant for this placement.'];
        }

        // Root cause 2: Low engagement ranking → flag for creative refresh
        if ($engageRank === 'BELOW_AVERAGE') {
            $flagged[] = [
                'ad' => $adName,
                'issue' => 'engagement_rate_ranking: BELOW_AVERAGE',
                'recommendation' => "Refresh visual creative for '{$adName}' — try video over static image, stronger hook in first 3 seconds.",
            ];
        }

        // Root cause 3: Low conversion ranking → trigger CRO audit
        if ($cvRank === 'BELOW_AVERAGE') {
            $landingPage = $campaign->landing_page_url ?: $customer->website;
            if ($landingPage) {
                $actions[] = ['ad_id' => $adId, 'action' => 'landing_page_audit_queued'];
                \App\Jobs\RunCroAudit::dispatch($customer->id, $landingPage)
                    ->delay(now()->addMinutes(5));
            }
            $flagged[] = [
                'ad' => $adName,
                'issue' => 'conversion_rate_ranking: BELOW_AVERAGE',
                'recommendation' => $landingPage ? 'A landing-page audit was queued for this campaign.' : 'Confirm the landing page for this campaign.',
            ];
        }
    }
}
