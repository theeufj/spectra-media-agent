<?php

namespace App\Services\Deployment;

use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Strategy;
use App\Services\FacebookAds\CampaignService;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\LinkedInAds\CampaignService as LinkedInCampaignService;
use App\Services\MicrosoftAds\CampaignService as MicrosoftCampaignService;
use Illuminate\Support\Facades\DB;

/**
 * Answers one question: do the platform objects this strategy claims to have
 * created actually exist?
 *
 * Extracted from VerifyDeployment so ReconcileStuckDeployments can ask the same
 * question of strategies that never reached a terminal state at all.
 */
class DeploymentVerifier
{
    /**
     * Can this platform be verified at all?
     *
     * All four platforms have a verification path. For anything else verify()
     * returns false meaning "not proven", which is not the same as "proven
     * absent" — callers must check this before treating a false as evidence
     * that a deployment failed.
     *
     * A platform whose kill switch is off is also unverifiable: the base service
     * skips authentication entirely, so every check would come back "absent" and
     * mail the customer a deployment failure for a campaign sitting on the
     * platform exactly as it was deployed.
     */
    public function supports(?string $platform): bool
    {
        $key = $this->platformKey($platform);

        return $key !== null && EnabledPlatform::isEnabled($key);
    }

    public function verify(Strategy $strategy, ?Customer $customer, bool $verifyConfiguration = true): bool
    {
        if (! $customer) {
            return false;
        }

        $platformIds = $strategy->execution_result['platform_ids'] ?? [];

        return match ($this->platformKey($strategy->platform)) {
            'google' => $this->verifyGoogleAds($strategy, $customer, $verifyConfiguration),
            'facebook' => $this->verifyFacebookAds($strategy, $customer, $platformIds),
            'microsoft' => $this->verifyMicrosoftAds($strategy, $customer, $platformIds),
            'linkedin' => $this->verifyLinkedInAds($strategy, $customer, $platformIds),
            // Returning false means "not proven", which callers must not treat
            // as "proven absent".
            default => false,
        };
    }

    /**
     * Canonical platform key for a stored strategy platform string, which ranges
     * from 'google' to 'Google Ads (SEM)'. Null when no verifier handles it.
     */
    private function platformKey(?string $platform): ?string
    {
        $platform = strtolower(trim($platform ?? ''));

        return match (true) {
            str_contains($platform, 'google') => 'google',
            str_contains($platform, 'facebook'), str_contains($platform, 'meta') => 'facebook',
            str_contains($platform, 'microsoft'), str_contains($platform, 'bing') => 'microsoft',
            str_contains($platform, 'linkedin') => 'linkedin',
            default => null,
        };
    }

    /**
     * The campaign identifier an execution agent recorded on the result.
     *
     * ExecutionResult::addPlatformId() is called with 'campaign' at every one of
     * its sixteen call sites; 'campaign_id' is written nowhere in the repo, so
     * reading only that key silently fell through to the model column on every
     * strategy — including the Facebook path, whose fallback is a different
     * column again.
     */
    private function platformCampaignId(array $platformIds): ?string
    {
        $id = $platformIds['campaign'] ?? $platformIds['campaign_id'] ?? null;

        return $id === null || $id === '' ? null : (string) $id;
    }

    private function googleCampaignResource(Strategy $strategy, Customer $customer): ?string
    {
        // A strategy's current object is authoritative. Legacy parent fallback
        // belongs only to the first Google strategy, as the reuse helper enforces.
        $id = $strategy->google_ads_campaign_id ?: ($this->platformCampaignId($strategy->execution_result['platform_ids'] ?? [])
            ?? $strategy->reusableGoogleCampaignId());
        if (! $id) {
            return null;
        }

        return str_starts_with($id, 'customers/') ? $id : 'customers/'.$customer->cleanGoogleCustomerId().'/campaigns/'.$id;
    }

    private function verifyGoogleAds(Strategy $strategy, Customer $customer, bool $verifyConfiguration): bool
    {
        $resourceName = $this->googleCampaignResource($strategy, $customer);
        if (! $resourceName || ! $customer->google_ads_customer_id) {
            return false;
        }
        $customerId = $customer->cleanGoogleCustomerId();
        $exists = app(GetCampaignStatus::class, ['customer' => $customer])($customerId, $resourceName) !== null;
        $baseline = $strategy->execution_result['metadata']['google_search_baseline'] ?? [];
        $type = strtolower($strategy->campaign_type ?? 'search');
        $isSearch = in_array($type, ['search', 'sem'], true) || $type === 'display' && ($baseline['version'] ?? null) === 1;
        if (! $exists || ! $verifyConfiguration || ! $isSearch) {
            return $exists;
        }
        $snapshot = app(\App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration::class, ['customer' => $customer])
            ->read($customerId, $resourceName);
        $issues = app(GoogleSearchConfigurationCheck::class)->compare($baseline, $snapshot);
        $issues = DB::transaction(function () use ($strategy, $customer, $resourceName, $baseline, $issues) {
            $locked = Strategy::whereKey($strategy->id)->lockForUpdate()->firstOrFail();
            $execution = $locked->execution_result ?? [];
            if (($execution['metadata']['google_search_baseline'] ?? []) != $baseline
                || $this->googleCampaignResource($locked, $customer) !== $resourceName) {
                $issues[] = 'The reviewed Google configuration changed during verification. Check it again before marking the deployment verified.';
            }
            // API reads happen outside the transaction. Merge only this result
            // into the latest row so concurrent readiness/goal ownership survives.
            $execution['metadata']['configuration_verification'] = ['checked_at' => now()->toIso8601String(),
                'passed' => $issues === [], 'issues' => $issues];
            $locked->forceFill(['execution_result' => $execution, 'deployment_error' => $issues ? implode(' ', $issues) : null])->save();

            return $issues;
        }, 3);
        $strategy->refresh();
        \App\Models\AgentActivity::record('deployment', $issues ? 'configuration_mismatch' : 'configuration_verified',
            $issues ? 'Google campaign settings need review: '.implode(' ', $issues) : 'Google campaign settings match the reviewed deployment.',
            $customer->id, $strategy->campaign_id, ['strategy_id' => $strategy->id, 'issues' => $issues], $issues ? 'needs_review' : 'completed');

        return $issues === [];
    }

    private function verifyFacebookAds(Strategy $strategy, Customer $customer, array $platformIds): bool
    {
        $fbCampaignId = $this->platformCampaignId($platformIds)
            ?? $strategy->facebook_campaign_id
            ?? $strategy->campaign->facebook_ads_campaign_id
            ?? null;

        if (! $fbCampaignId || ! $customer->facebook_ads_account_id) {
            return false;
        }

        $campaigns = (new CampaignService($customer))->listCampaigns($customer->facebook_ads_account_id);

        if ($campaigns === null) {
            return false;
        }

        foreach ($campaigns as $campaign) {
            if (($campaign['id'] ?? '') === (string) $fbCampaignId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Microsoft answers GetCampaignsByIds with an empty Campaigns block for an
     * id that does not exist, so a status string is proof the campaign is there.
     * A transport failure throws out of the SOAP client rather than returning
     * null — which is what we want: both callers catch, and neither turns an
     * unanswered question into "the deployment failed".
     */
    private function verifyMicrosoftAds(Strategy $strategy, Customer $customer, array $platformIds): bool
    {
        $msCampaignId = $this->platformCampaignId($platformIds)
            ?? $strategy->campaign->microsoft_ads_campaign_id
            ?? null;

        if (! $msCampaignId || ! $customer->microsoft_ads_account_id) {
            return false;
        }

        return (new MicrosoftCampaignService($customer))->getCampaignStatus((string) $msCampaignId) !== null;
    }

    /**
     * LinkedIn's GET adCampaigns/{id} answers 404 for an unknown campaign, which
     * BaseLinkedInAdsService turns into null — as it does any transport failure,
     * so a false here is exactly as strong as the Google path's and no stronger.
     */
    private function verifyLinkedInAds(Strategy $strategy, Customer $customer, array $platformIds): bool
    {
        $liCampaignId = $this->platformCampaignId($platformIds)
            ?? $strategy->campaign->linkedin_campaign_id
            ?? null;

        if (! $liCampaignId || ! $customer->linkedin_ads_account_id) {
            return false;
        }

        $campaign = (new LinkedInCampaignService($customer))->getCampaign((string) $liCampaignId);

        return ! empty($campaign);
    }
}
