<?php

namespace App\Services\Agents\Google\Executors;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Services\Agents\AgentIssue;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\AdExtensionBuilder;
use App\Services\Agents\Google\AudienceTargeter;
use App\Services\Agents\Google\BiddingStrategyApplier;
use App\Services\Agents\Google\GeoTargetResolver;
use App\Services\Agents\Google\GoogleCampaignSchedule;
use App\Services\Agents\Google\LandingUrlBuilder;
use App\Services\Agents\Google\SearchKeywordBuilder;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\CampaignStatusHelper;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\DisplayServices\UploadImageAsset;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use App\Services\GoogleAds\SearchServices\CreateResponsiveSearchAd;
use App\Services\GoogleAds\SearchServices\CreateSearchAdGroup;
use App\Services\GoogleAds\SearchServices\CreateSearchCampaign;
use App\Services\StorageHelper;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus as GoogleCampaignStatus;
use Illuminate\Support\Facades\Log;

/**
 * Deploys a Google Ads Search campaign.
 * Extracted verbatim from GoogleAdsExecutionAgent.
 */
class SearchCampaignExecutor implements CampaignTypeExecutor
{
    public function __construct(
        protected Customer $customer,
        protected GeoTargetResolver $geo,
        protected AdExtensionBuilder $extensions,
        protected LandingUrlBuilder $urls,
        protected BiddingStrategyApplier $bidding,
        protected SearchKeywordBuilder $keywords,
        protected AudienceTargeter $audiences,
    ) {}

    public function execute(
        string $customerId,
        Campaign $campaign,
        Strategy $strategy,
        ExecutionPlan $plan,
        ExecutionResult $result
    ): void {
        // Every operation uses this customer's sub-account under our MCC.
        if ($this->customer->google_ads_customer_id && $customerId !== $this->customer->cleanGoogleCustomerId()) {
            Log::warning('GoogleAdsExecutionAgent: Customer ID mismatch, switching to stored account ID', [
                'provided_id' => $customerId,
                'stored_id' => $this->customer->google_ads_customer_id,
            ]);
            $customerId = $this->customer->cleanGoogleCustomerId();
        }

        $setupOnlyStaging = $this->customer->fresh()?->isPaidSetupOnly() === true;
        if ($setupOnlyStaging && ! $this->confirmSetupAuthorization($campaign, $customerId, $result)) {
            return;
        }

        // A tracking action's existence does not make it an eligible bidding
        // target. Validate the reviewed objective before creating live objects.
        $goalService = app(ReconcileCampaignConversionGoals::class, ['customer' => $this->customer]);
        $goalReadiness = $goalService->prepare($strategy);
        if (! $setupOnlyStaging && ! $this->recordGoalReadiness($goalReadiness, $result, $strategy)) {
            return;
        }

        Log::info('GoogleAdsExecutionAgent: Creating Search Campaign in account', [
            'customer_id' => $customerId,
        ]);

        // 1. Create Campaign — idempotency guard prevents duplicates on retry
        $timestamp = now()->format('Ymd_His');
        $newCampaign = false;
        if ($reusableGoogleCampaign = $strategy->reusableGoogleCampaignId()) {
            $campaignResourceName = $reusableGoogleCampaign;
            Log::info('GoogleAdsExecutionAgent: Reusing existing Search campaign from prior attempt', [
                'campaign_id' => $campaign->id,
                'google_ads_campaign_id' => $campaignResourceName,
            ]);
            $result->addPlatformId('campaign', $campaignResourceName);
        } else {
            $createCampaignService = app(CreateSearchCampaign::class, ['customer' => $this->customer]);
            $campaignName = $campaign->name.' - '.$timestamp;

            $campaignData = [
                'businessName' => $campaignName,
                'budget' => $strategy->daily_budget ?: ($campaign->daily_budget ?: $campaign->total_budget / 30),
                'status' => 'PAUSED',
                ...GoogleCampaignSchedule::for($campaign),
            ];

            $campaignResourceName = ($createCampaignService)($customerId, $campaignData);
            if (! $campaignResourceName) {
                throw new \Exception('Failed to create search campaign');
            }

            $result->addPlatformId('campaign', $campaignResourceName);
            $strategy->recordGoogleCampaignId($campaignResourceName);
            $newCampaign = true;
        }

        // Retrying an ENABLED campaign could expose keyword/bid changes to live
        // traffic before their demand forecast is checked. Pause is an explicit
        // owner decision; a deployment retry never pauses and resumes it itself.
        if ($setupOnlyStaging && ! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#', $campaignResourceName)) {
            $result->addError('setup_search_staging_account_mismatch', 'The staged Search campaign does not belong to this customer account. No configuration or ad changes were made.');

            return;
        }
        if (! $this->confirmPausedStage($customerId, $campaignResourceName, $result)) {
            return;
        }
        if ($setupOnlyStaging && ! $this->confirmSetupAuthorization($campaign, $customerId, $result)) {
            return;
        }
        $priorIntent = $strategy->fresh()->execution_result['metadata']['google_search_staging'] ?? [];
        $enableAfterSuccess = $newCampaign
            ? CampaignStatusHelper::getGoogleAdsStatus(null, $this->customer) === GoogleCampaignStatus::ENABLED
            : (($priorIntent['campaign_resource'] ?? null) === $campaignResourceName && ($priorIntent['enable_after_success'] ?? false) === true);
        $result->addMetadata('google_search_staging', ['campaign_resource' => $campaignResourceName,
            'verified_platform_status' => 'PAUSED', 'checked_at' => now()->toIso8601String(),
            ...($setupOnlyStaging ? ['mode' => 'setup_only_paused_handover'] : []),
            'enable_after_success' => ! $setupOnlyStaging && $enableAfterSuccess && $campaign->status !== \App\Enums\CampaignStatus::Paused
                && ! $campaign->spend_safety_hold && ! CampaignSpendGuardrails::activeTrial($campaign)]);

        // Configure only this campaign, before adding ads or Smart Bidding.
        // Explicit deployment can configure a staged PAUSED campaign, without
        // enabling it or changing any other campaign's conversion actions.
        // A paid setup is delivered paused before the customer completes
        // tracking. Never guess or relabel a different conversion objective.
        if (! $setupOnlyStaging || ($goalReadiness['ready'] ?? false)) {
            $goalReadiness = $goalService->reconcile($strategy, $campaignResourceName, allowInactive: true);
        }
        if (! $this->recordGoalReadiness($goalReadiness, $result, $strategy, $setupOnlyStaging)) {
            return;
        }
        $goalsReady = ($goalReadiness['ready'] ?? false) === true;
        if ($setupOnlyStaging && ! $goalsReady && ! $this->confirmManualBidding($customerId, $campaignResourceName, $result)) {
            return;
        }

        // 1.5 Add Location Targeting
        $this->geo->addLocationTargeting($customerId, $campaignResourceName, $campaign, $strategy, $plan, $result);

        // 2. Create Ad Group — reuse if already created in a prior attempt
        if (! empty($strategy->google_ads_ad_group_id)) {
            $adGroupResourceName = $strategy->google_ads_ad_group_id;
            Log::info('GoogleAdsExecutionAgent: Reusing existing Search ad group from prior attempt', [
                'strategy_id' => $strategy->id,
                'google_ads_ad_group_id' => $adGroupResourceName,
            ]);
            $result->addPlatformId('ad_group', $adGroupResourceName);
        } else {
            $createAdGroupService = app(CreateSearchAdGroup::class, ['customer' => $this->customer]);
            $adGroupName = 'Default Ad Group - '.$timestamp;
            $adGroupResourceName = ($createAdGroupService)($customerId, $campaignResourceName, $adGroupName);
            if (! $adGroupResourceName) {
                throw new \Exception('Failed to create search ad group');
            }

            $result->addPlatformId('ad_group', $adGroupResourceName);
            $strategy->google_ads_ad_group_id = $adGroupResourceName;
            $strategy->save();
        }

        // 3. Preserve the selected keywords; Planner supplies metrics, not replacements.
        $keywords = $this->keywords->getKeywords($campaign, $strategy, $plan);
        if (empty($keywords)) {
            // Fallback: use AI keyword research to generate initial keywords
            $keywords = $this->keywords->researchKeywords($customerId, $campaign, $strategy);
        }
        // Missing Planner metrics must not erase a reviewed keyword or widen its match type.
        if (! empty($keywords)) {
            $keywords = $this->keywords->validateAndEnrichKeywords($customerId, $keywords, $campaign, $strategy);
        }
        if ($keywords === []) {
            throw new \RuntimeException('No relevant Search keywords could be selected. Review the campaign keywords before retrying.');
        }
        $result->addMetadata('selected_keywords', $keywords);
        $this->keywords->addKeywords($customerId, $adGroupResourceName, $keywords, $result);

        // 3.2 Add negative keywords at campaign creation time
        $this->keywords->addInitialNegativeKeywords($customerId, $campaignResourceName, $campaign, $strategy, $result);

        // 3.5 Add Audience Targeting
        $this->audiences->addAudienceTargeting($customerId, $adGroupResourceName, $strategy, $result);

        // Stage the effective bidding settings before forecasting, while there
        // are no serving RSAs. A suggested market bid is not the approved bid.
        if (! $setupOnlyStaging || $goalsReady) {
            $this->bidding->applyBiddingStrategy($customerId, $campaignResourceName, $strategy, $result);
        } else {
            $result->addWarning('setup_smart_bidding_deferred', 'The paused campaign retains verified Manual CPC. The suggested bidding strategy was not applied because the reviewed conversion goal is not ready. Review tracking and bidding before activation.');
        }
        if ($setupOnlyStaging) {
            // An activation forecast is a separate decision from constructing
            // assets that cannot serve. Do not turn an unchecked forecast green.
            $prior = $strategy->fresh()->execution_result['metadata']['search_reach_preflight'] ?? null;
            if (($prior['status'] ?? null) === 'not_checked') {
                $prior = $prior['previous_assessment'] ?? null;
            }
            $result->addMetadata('search_reach_preflight', ['status' => 'not_checked',
                'checked_at' => now()->toIso8601String(), 'activation_required' => true,
                'gate' => ['ready' => false, 'status' => 'not_checked', 'issues' => [[
                    'code' => 'setup_search_reach_activation_required',
                    'message' => 'Current keyword reach and spending settings must be checked before activating the paused campaign.',
                ]]],
                ...($prior ? ['previous_assessment' => $prior] : []),
            ]);
            $result->addWarning('setup_search_reach_activation_required', 'The campaign is being built paused. Keyword reach, billing and tracking still require review before you activate it.');
        } elseif (! $this->keywords->preflightReach($campaign, $strategy, $customerId, $campaignResourceName, $result)) {
            return;
        }

        // 4. Save images to the account library. Search image links are read-only
        // through the API; attaching them requires the Google Ads UI.
        // https://developers.google.com/google-ads/api/docs/assets/overview
        $imageAssetResourceNames = [];
        $imageCollaterals = ImageCollateral::forStrategy($strategy)->where('is_active', true)->where('should_deploy', true)->limit(15)->get();
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

        if ($imageAssetResourceNames !== []) {
            $result->addWarning(
                'search_images_require_manual_linking',
                'Images are saved in your Google Ads asset library. Add them to Search ads in Google Ads once your account meets its image-asset eligibility requirements.',
            );
        }

        if ($setupOnlyStaging && (! $this->confirmSetupAuthorization($campaign, $customerId, $result)
            || ! $this->confirmPausedStage($customerId, $campaignResourceName, $result))) {
            return;
        }

        // 5. Create Responsive Search Ads (2-3 variants per Google best practices)
        $adCopies = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->limit(3)->get();
        if ($adCopies->isEmpty()) {
            $adCopies = $strategy->adCopies()->limit(3)->get();
        }
        $expectedAds = [];

        if ($adCopies->isNotEmpty()) {
            $finalUrl = $this->urls->getFinalUrl($campaign, $strategy, $plan);

            if (! $finalUrl) {
                $result->addWarning('No landing page URL found for ad creation. Skipping ad creation.');
            } else {
                $createAdService = app(CreateResponsiveSearchAd::class, ['customer' => $this->customer]);

                foreach ($adCopies as $adCopy) {
                    $expectedAds[] = ['ad_copy_id' => $adCopy->id, 'headlines' => $adCopy->headlines ?? [], 'descriptions' => $adCopy->descriptions ?? [], 'final_urls' => [$finalUrl]];
                    $adData = [
                        'finalUrls' => [$finalUrl],
                        'headlines' => $adCopy->headlines ?? [],
                        'descriptions' => $adCopy->descriptions ?? [],
                    ];
                    $adResourceName = ($createAdService)($customerId, $adGroupResourceName, $adData);
                    if ($adResourceName) {
                        $result->addPlatformId('ad', $adResourceName);
                        $expectedAds[array_key_last($expectedAds)]['resource'] = $adResourceName;
                    }
                }

                // If only 1 ad copy with enough headlines, create a second RSA variant
                // with rotated headlines so Google has two containers to A/B test.
                if ($adCopies->count() === 1) {
                    $firstCopy = $adCopies->first();
                    $allHeadlines = $firstCopy->headlines ?? [];
                    if (count($allHeadlines) >= 6) {
                        $rotated = array_merge(array_slice($allHeadlines, 4), array_slice($allHeadlines, 0, 4));
                        $adData2 = [
                            'finalUrls' => [$finalUrl],
                            'headlines' => $rotated,
                            'descriptions' => array_reverse($firstCopy->descriptions ?? []),
                        ];
                        $adResourceName2 = ($createAdService)($customerId, $adGroupResourceName, $adData2);
                        if ($adResourceName2) {
                            $result->addPlatformId('ad', $adResourceName2);
                        }
                    }
                }
            }
        }

        // 6. Add Ad Extensions (Sitelinks, Callouts)
        $this->extensions->createAndLinkAdExtensions($customerId, $campaignResourceName, $strategy, $result);

        $resources = $result->metadata['platform_resources'] ?? [];
        $extensionTypes = ['sitelink_asset', 'callout_asset', 'structured_snippet_asset', 'call_asset', 'price_asset', 'promotion_asset'];
        $result->addMetadata('google_search_baseline', [
            'version' => 1, 'keywords' => $keywords, 'ads' => $expectedAds,
            'locations' => $result->metadata['expected_locations'] ?? [],
            'location_mode' => 'PRESENCE',
            'networks' => ['targetGoogleSearch' => true, 'targetSearchNetwork' => false, 'targetContentNetwork' => false],
            'budget_micros' => (int) round(($strategy->daily_budget ?: ($campaign->daily_budget ?: $campaign->total_budget / 30)) * 100) * 10000,
            'bidding' => $result->metadata['expected_bidding'] ?? 'MANUAL_CPC',
            'asset_resources' => array_merge(...array_map(fn ($type) => $resources[$type] ?? [], $extensionTypes)),
            'verified_offer_assets' => array_merge($resources['price_asset'] ?? [], $resources['promotion_asset'] ?? []),
            'verified_offer_details' => $result->metadata['verified_offer_details'] ?? [],
            'sitelink_urls' => array_column(app(\App\Services\Campaigns\AdvertisingEvidence::class)->sitelinks($this->customer,
                $strategy->ad_extensions['sitelinks'] ?? $strategy->bidding_strategy['sitelinks'] ?? []), 'url'),
            'conversion_category' => ReconcileCampaignConversionGoals::categoryFor($strategy),
            'conversion_goal' => $goalReadiness['intent'] ?? null,
        ]);

        // 8. Apply conversion value rules (device + audience modifiers)
        if (! $setupOnlyStaging || $goalsReady) {
            try {
                $applyValueRules = app(\App\Services\GoogleAds\CommonServices\ApplyConversionValueRules::class, ['customer' => $this->customer]);
                $applyValueRules($customerId, $campaignResourceName, $this->customer);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('GoogleAdsExecutionAgent: Conversion value rules not applied: '.$e->getMessage());
            }
        }
        if ($setupOnlyStaging && $this->confirmSetupAuthorization($campaign, $customerId, $result)
            && $this->confirmPausedStage($customerId, $campaignResourceName, $result)) {
            $staging = $result->metadata['google_search_staging'];
            $staging['checked_at'] = now()->toIso8601String();
            $result->addMetadata('google_search_staging', $staging);
        }
    }

    protected function confirmPausedStage(string $customerId, string $resource, ExecutionResult $result): bool
    {
        try {
            $status = app(GetCampaignStatus::class, ['customer' => $this->customer])($customerId, $resource);
            if (($status['status'] ?? null) === GoogleCampaignStatus::PAUSED) {
                return true;
            }
            $result->addError('search_staging_pause_required', 'The Google Search campaign must be verified paused before deployment changes can continue. Review its live status; further keyword, targeting and ad changes are on hold.');
        } catch (\Throwable $e) {
            report($e);
            $result->addError('search_staging_status_unavailable', 'The Google Search campaign’s paused status could not be verified. Deployment changes are held for a fresh check.');
        }

        return false;
    }

    private function recordGoalReadiness(array $readiness, ExecutionResult $result, Strategy $strategy, bool $pausedSetup = false): bool
    {
        // Keep custom-goal ownership and the last verified configuration when
        // the surrounding deployment serializes its execution result.
        $persisted = $strategy->fresh()->execution_result['metadata']['conversion_goal_readiness'] ?? [];
        $result->addMetadata('conversion_goal_readiness', array_replace($persisted, $readiness));
        if ($readiness['ready'] ?? false) {
            return true;
        }
        $issues = AgentIssue::list($readiness['issues'] ?? []);
        if ($issues === []) {
            $issues[] = new AgentIssue('conversion_goal_unready', 'The selected conversion goal could not be verified. Review tracking before launching.');
        }
        foreach ($issues as $issue) {
            if ($pausedSetup) {
                $result->addWarning($issue->code, $issue->message.' Resolve this before activating the paused campaign.');
            } else {
                $result->addError($issue->code, $issue->message);
            }
        }

        return $pausedSetup;
    }

    private function confirmSetupAuthorization(Campaign $campaign, string $customerId, ExecutionResult $result): bool
    {
        $current = $campaign->fresh();
        $customer = $this->customer->fresh();
        if (! $current || ! $customer?->isPaidSetupOnly() || $current->customer_id !== $customer->id
            || $customer->cleanGoogleCustomerId() !== $customerId || $current->hasPassedEndDate()
            || in_array($current->status, [\App\Enums\CampaignStatus::Completed, \App\Enums\CampaignStatus::Ended], true)
            || $current->spend_safety_hold) {
            $result->addError('setup_search_staging_authorization_changed', 'The paid setup, customer account or campaign authorization changed. Paused construction requires a fresh review.');

            return false;
        }

        return true;
    }

    private function confirmManualBidding(string $customerId, string $resource, ExecutionResult $result): bool
    {
        try {
            $configuration = app(ReadCampaignConfiguration::class, ['customer' => $this->customer])->read($customerId, $resource);
            $campaign = $configuration['campaign']['campaign'] ?? [];
            $bidding = $campaign['biddingStrategyType'] ?? $campaign['bidding_strategy_type'] ?? null;
            if (is_int($bidding)) {
                $bidding = BiddingStrategyType::name($bidding);
            }
            if ($bidding === 'MANUAL_CPC') {
                $result->addMetadata('expected_bidding', 'MANUAL_CPC');

                return true;
            }
        } catch (\Throwable $e) {
            report($e);
        }
        $result->addError('setup_search_manual_bidding_required', 'The paused campaign must have verified Manual CPC while its conversion goal is unready. Existing bidding settings were left unchanged; review them before retrying.');

        return false;
    }

    /**
     * Apply the strategy's recommended bidding strategy to a campaign.
     * Automatically downgrades Smart Bidding strategies to MaximizeConversions
     * when the account has insufficient conversion history.
     */
}
