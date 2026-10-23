<?php

namespace App\Services\Agents\Google\Executors;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Services\Agents\AgentIssue;
use App\Services\Agents\ExecutionBudgetGuard;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\AdExtensionBuilder;
use App\Services\Agents\Google\BiddingStrategyApplier;
use App\Services\Agents\Google\GeoTargetResolver;
use App\Services\Agents\Google\GoogleCampaignSchedule;
use App\Services\Agents\Google\LandingUrlBuilder;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\DisplayServices\CreateDisplayAdGroup;
use App\Services\GoogleAds\DisplayServices\CreateDisplayCampaign;
use App\Services\GoogleAds\DisplayServices\CreateResponsiveDisplayAd;
use App\Services\GoogleAds\DisplayServices\DisplayBiddingConfiguration;
use App\Services\GoogleAds\DisplayServices\UploadImageAsset;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use App\Services\StorageHelper;
use Illuminate\Support\Facades\Log;

/**
 * Deploys a Google Ads Display campaign.
 * Extracted verbatim from GoogleAdsExecutionAgent.
 */
class DisplayCampaignExecutor implements CampaignTypeExecutor
{
    public function __construct(
        protected Customer $customer,
        protected GeoTargetResolver $geo,
        protected AdExtensionBuilder $extensions,
        protected LandingUrlBuilder $urls,
        protected BiddingStrategyApplier $bidding,
    ) {}

    public function execute(
        string $customerId,
        Campaign $campaign,
        Strategy $strategy,
        ExecutionPlan $plan,
        ExecutionResult $result
    ): void {
        $desired = DisplayBiddingConfiguration::desired($strategy->bidding_strategy ?? []);
        if ($desired === null) {
            $result->addMetadata('display_initial_bidding', ['status' => 'needs_review', 'enable_after_success' => false]);
            $result->addError('display_initial_bid_needs_review', 'Display Manual CPC needs a reviewed Display bid estimate. Choose a supported conversion bidding strategy with a ready conversion goal before building this campaign.');

            return;
        }
        if (! $this->confirmAuthorization($campaign, $strategy, $desired, $result)) {
            return;
        }
        $goals = app(ReconcileCampaignConversionGoals::class, ['customer' => $this->customer]);
        if (! $this->recordGoalReadiness($goals->prepare($strategy), $result)) {
            return;
        }
        if (! $this->confirmAuthorization($campaign, $strategy, $desired, $result)) {
            return;
        }

        // Verify we are targeting the correct account
        if ($this->customer->google_ads_customer_id && $customerId !== $this->customer->google_ads_customer_id) {
            Log::warning('GoogleAdsExecutionAgent: Customer ID mismatch, switching to stored account ID', [
                'provided_id' => $customerId,
                'stored_id' => $this->customer->google_ads_customer_id,
            ]);
            $customerId = $this->customer->google_ads_customer_id;
        }

        Log::info('GoogleAdsExecutionAgent: Creating Display Campaign in account', [
            'customer_id' => $customerId,
        ]);

        // 1. Create Campaign — idempotency guard prevents duplicates on retry
        $timestamp = now()->format('Ymd_His');
        if ($reusableGoogleCampaign = $strategy->reusableGoogleCampaignId()) {
            $campaignResourceName = $reusableGoogleCampaign;
            Log::info('GoogleAdsExecutionAgent: Reusing existing Display campaign from prior attempt', [
                'campaign_id' => $campaign->id,
                'google_ads_campaign_id' => $campaignResourceName,
            ]);
            $result->addPlatformId('campaign', $campaignResourceName);
        } else {
            $createCampaignService = app(CreateDisplayCampaign::class, ['customer' => $this->customer]);
            $campaignName = $campaign->name.' - '.$timestamp;

            $campaignData = [
                'businessName' => $campaignName,
                'budget' => $strategy->fresh()?->daily_budget,
                'status' => 'PAUSED',
                'biddingStrategyType' => $desired['type'],
                ...($desired['type'] === 'TARGET_CPA' ? ['targetCpaMicros' => $desired['target_cpa_micros']] : []),
                ...GoogleCampaignSchedule::for($campaign),
            ];

            $campaignResourceName = ($createCampaignService)($customerId, $campaignData);
            if (! $campaignResourceName) {
                throw new \Exception('Failed to create display campaign');
            }

            $result->addPlatformId('campaign', $campaignResourceName);
            $strategy->recordGoogleCampaignId($campaignResourceName);
        }

        if (! $this->confirmConfiguration($customerId, $campaignResourceName, $campaign, $strategy, $desired, $result)
            || ! $this->recordGoalReadiness($goals->inspect($strategy, $campaignResourceName), $result)) {
            return;
        }
        if (! $this->confirmConfiguration($customerId, $campaignResourceName, $campaign, $strategy, $desired, $result)) {
            return;
        }

        // 1.5 Add Location Targeting
        $this->geo->addLocationTargeting($customerId, $campaignResourceName, $campaign, $strategy, $plan, $result);

        // 2. Create Ad Group — reuse if already created in a prior attempt
        if (! empty($strategy->google_ads_ad_group_id)) {
            $adGroupResourceName = $strategy->google_ads_ad_group_id;
            Log::info('GoogleAdsExecutionAgent: Reusing existing Display ad group from prior attempt', [
                'strategy_id' => $strategy->id,
                'google_ads_ad_group_id' => $adGroupResourceName,
            ]);
            $result->addPlatformId('ad_group', $adGroupResourceName);
        } else {
            $createAdGroupService = app(CreateDisplayAdGroup::class, ['customer' => $this->customer]);
            $adGroupName = 'Default Ad Group - '.$timestamp;
            $adGroupResourceName = ($createAdGroupService)($customerId, $campaignResourceName, $adGroupName);
            if (! $adGroupResourceName) {
                throw new \Exception('Failed to create display ad group');
            }

            $result->addPlatformId('ad_group', $adGroupResourceName);
            $strategy->google_ads_ad_group_id = $adGroupResourceName;
            $strategy->save();
        }

        if (! $this->confirmConfiguration($customerId, $campaignResourceName, $campaign, $strategy, $desired, $result)) {
            return;
        }

        // 3. Upload Image Assets
        $imageCollaterals = ImageCollateral::forStrategy($strategy)->where('is_active', true)->where('should_deploy', true)->get();
        $imageAssetResourceNames = [];

        if ($imageCollaterals->isNotEmpty()) {
            $uploadImageAssetService = new UploadImageAsset($this->customer);
            foreach ($imageCollaterals as $image) {
                try {
                    $imageData = StorageHelper::get($image->s3_path);
                    if (! $imageData) {
                        Log::warning('GoogleAdsExecutionAgent: Image data is null, skipping upload', [
                            's3_path' => $image->s3_path,
                        ]);

                        continue;
                    }

                    $assetResourceName = ($uploadImageAssetService)($customerId, $imageData, $image->s3_path);
                    if ($assetResourceName) {
                        $imageAssetResourceNames[] = $assetResourceName;
                        $result->addPlatformId('image_asset', $assetResourceName);
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $result->addWarning("Failed to upload image asset {$image->s3_path}: ".$e->getMessage());
                }
            }
        }

        // 4. Create Responsive Display Ad
        $adCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->first();
        if ($adCopy && ! empty($imageAssetResourceNames)) {
            $finalUrl = $this->urls->getFinalUrl($campaign, $strategy, $plan);

            if (! $finalUrl) {
                $result->addWarning('No landing page URL found for display ad creation. Skipping ad creation.');
            } else {
                $createAdService = new CreateResponsiveDisplayAd($this->customer);
                $adData = [
                    'finalUrls' => [$finalUrl],
                    'headlines' => $adCopy->headlines ?? [],
                    'longHeadlines' => [$adCopy->headlines[0] ?? 'Get Started Today'],
                    'descriptions' => $adCopy->descriptions ?? [],
                    'imageAssets' => $imageAssetResourceNames,
                ];

                $adResourceName = ($createAdService)($customerId, $adGroupResourceName, $adData);
                if ($adResourceName) {
                    $result->addPlatformId('ad', $adResourceName);
                }
            }
        }

        // 5. Add Ad Extensions
        // Note: Display campaigns support fewer extensions, but Sitelinks/Callouts are often compatible.
        $this->extensions->createAndLinkAdExtensions($customerId, $campaignResourceName, $strategy, $result);

        // 6. Apply conversion value rules (device + audience modifiers)
        try {
            $applyValueRules = app(\App\Services\GoogleAds\CommonServices\ApplyConversionValueRules::class, ['customer' => $this->customer]);
            $applyValueRules($customerId, $campaignResourceName, $this->customer);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('GoogleAdsExecutionAgent: Conversion value rules not applied: '.$e->getMessage());
        }
    }

    /** @phpstan-impure Fresh authorization can change across intervening API reads. */
    private function confirmAuthorization(Campaign $campaign, Strategy $strategy, array $desired, ExecutionResult $result): bool
    {
        if (CampaignSpendGuardrails::automaticChangesSuspended($campaign)) {
            $result->addError('display_spending_changes_on_hold', 'Display bidding changes are on hold while spending is restricted or a bounded trial is active.');

            return false;
        }
        $context = new ExecutionContext($strategy, $campaign, $this->customer);
        $validation = (new ExecutionBudgetGuard)->validate(new ExecutionPlan([]), $context, 'google');
        foreach ($validation->errors as $issue) {
            $result->addError($issue->code, $issue->message);
        }
        if (! $validation->passed) {
            return false;
        }
        if (DisplayBiddingConfiguration::desired($context->strategy->bidding_strategy ?? []) !== $desired
            || $context->strategy->conversion_goals !== $strategy->conversion_goals
            || $context->strategy->google_ads_ad_group_id !== $strategy->google_ads_ad_group_id
            || $context->campaign->customer_id !== $this->customer->id
            || $this->customer->fresh()?->cleanGoogleCustomerId() !== $this->customer->cleanGoogleCustomerId()
            || $context->campaign->hasPassedEndDate()) {
            $result->addError('display_bidding_authorization_changed', 'The reviewed Display configuration changed. Review it before retrying.');

            return false;
        }

        return true;
    }

    /** @phpstan-impure Both remote configuration and database approval are read afresh. */
    private function confirmConfiguration(string $customerId, string $resource, Campaign $campaign, Strategy $strategy, array $desired, ExecutionResult $result): bool
    {
        $actual = app(DisplayBiddingConfiguration::class, ['customer' => $this->customer])->read($customerId, $resource);
        $fresh = $strategy->fresh();
        if (! $this->confirmAuthorization($campaign, $strategy, $desired, $result)) {
            return false;
        }
        if (! DisplayBiddingConfiguration::isPausedAutomated($actual)
            || $fresh?->reusableGoogleCampaignId() !== $resource
            || ($actual['campaign_resource'] ?? null) !== $resource
            || ($actual['bidding_strategy'] ?? null) !== $desired['type']
            || ($actual['shared_budget'] ?? null) !== false
            || ($actual['daily_budget_micros'] ?? null) !== CampaignSpendGuardrails::moneyToMicros($fresh->daily_budget)
            || ($desired['type'] === 'TARGET_CPA' && ($actual['target_cpa_micros'] ?? null) !== $desired['target_cpa_micros'])) {
            $result->addError('display_initial_bidding_unverified', 'Display creation is on hold until Google confirms the paused campaign, approved daily budget and selected automated bidding. Existing campaign bids were left unchanged.');

            return false;
        }
        $result->addMetadata('display_initial_bidding', [...$actual, 'status' => 'configured', 'verified_platform_status' => 'PAUSED',
            'source' => 'google_ads_api', 'checked_at' => now()->toIso8601String(), 'enable_after_success' => false,
            'activation_ready' => false, 'activation_message' => 'The Display build is paused. Review conversion delivery and bidding before activation.']);

        return true;
    }

    private function recordGoalReadiness(array $readiness, ExecutionResult $result): bool
    {
        $result->addMetadata('conversion_goal_readiness', $readiness);
        if (($readiness['ready'] ?? false) === true) {
            return true;
        }
        $result->addError('display_conversion_goal_needs_review', 'Verify the selected conversion goal before using automated conversion bidding for Display.');
        foreach (AgentIssue::list($readiness['issues'] ?? []) as $issue) {
            $result->addWarning($issue->code, $issue->message);
        }

        return false;
    }
}
