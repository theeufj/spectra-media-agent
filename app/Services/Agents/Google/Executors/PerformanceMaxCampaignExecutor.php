<?php

namespace App\Services\Agents\Google\Executors;

use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\AdExtensionBuilder;
use App\Services\Agents\Google\BiddingStrategyApplier;
use App\Services\Agents\Google\GeoTargetResolver;
use App\Services\Agents\Google\GoogleCampaignSchedule;
use App\Services\Agents\Google\LandingUrlBuilder;
use App\Services\Creative\CreativeRelease;
use App\Services\Creative\GoogleCreativeCta;
use App\Services\GoogleAds\CommonServices\CreateTextAsset;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\DisplayServices\UploadImageAsset;
use App\Services\GoogleAds\PerformanceMaxServices\AddAudienceSignals;
use App\Services\GoogleAds\PerformanceMaxServices\CreateAssetGroupWithAssets;
use App\Services\GoogleAds\PerformanceMaxServices\CreatePerformanceMaxCampaign;
use App\Services\GoogleAds\PerformanceMaxServices\LinkAssetGroupAsset;
use App\Services\GoogleAds\PerformanceMaxServices\PerformanceMaxAssetSpec;
use App\Services\GoogleAds\PerformanceMaxServices\PerformanceMaxCopy;
use App\Services\GoogleAds\PerformanceMaxServices\PerformanceMaxLaunchCheck;
use App\Services\GoogleAds\PerformanceMaxServices\PerformanceMaxLogo;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use App\Services\GoogleAds\VideoServices\UploadVideoAsset;
use App\Services\StorageHelper;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;
use Google\Ads\GoogleAds\V26\Enums\CallToActionTypeEnum\CallToActionType;
use Illuminate\Support\Facades\Log;

/**
 * Deploys a Google Ads Performance Max campaign.
 * Extracted verbatim from GoogleAdsExecutionAgent.
 */
class PerformanceMaxCampaignExecutor implements CampaignTypeExecutor
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
        // Verify we are targeting the correct account
        if ($customerId !== $campaign->customer->cleanGoogleCustomerId() || $campaign->customer_id !== $this->customer->id) {
            throw new \RuntimeException('The Performance Max campaign does not match the current Google account.');
        }
        $adCopy = $strategy->deployableAdCopies()->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->first();
        if (! $adCopy instanceof AdCopy || ! PerformanceMaxCopy::hasCompleteDescriptions($adCopy->descriptions ?? [])) {
            $result->addError('pmax_copy_incomplete', 'Generate and select complete Performance Max ad copy before launching.');

            return;
        }
        $textAssets = PerformanceMaxAssetSpec::text($adCopy);
        if (count($textAssets[AssetFieldType::HEADLINE]) < 3 || $textAssets[AssetFieldType::LONG_HEADLINE] === []
            || count($textAssets[AssetFieldType::DESCRIPTION]) < 2 || ! array_any($textAssets[AssetFieldType::DESCRIPTION], fn (string $text) => mb_strlen($text) <= 60)) {
            $result->addError('pmax_copy_incomplete', 'Performance Max needs three headlines, a long headline and two descriptions, including a description of 60 characters or fewer.');

            return;
        }
        $imageCollaterals = ImageCollateral::forDeployment($strategy)->where('campaign_id', $campaign->id)->get();
        $reviewedLogos = $strategy->creative_approval['logo_hashes'] ?? [];
        if (! $imageCollaterals->contains(fn (ImageCollateral $image) => isset($reviewedLogos[$image->id])
            && $reviewedLogos[$image->id] === PerformanceMaxLogo::digest($image, $strategy))) {
            $result->addError('pmax_business_logo_required', PerformanceMaxLogo::REQUIRED);

            return;
        }
        $businessName = trim((string) $this->customer->name);
        if ($businessName === '') {
            $result->addError('pmax_business_name_required', 'Add the advertiser’s business name to its customer profile before launching Performance Max.');

            return;
        }
        $goals = app(ReconcileCampaignConversionGoals::class, ['customer' => $this->customer]);
        $intent = $strategy->bidding_strategy['pmax_conversion_goal'] ?? null;
        $prepared = $goals->prepare($strategy);
        if (! $intent || ($prepared['ready'] ?? false) !== true || $intent != ($prepared['intent'] ?? null)) {
            $result->addError('pmax_conversion_goal_unverified', 'Review the Performance Max conversion goal and rerun market research before creating the ads.');

            return;
        }

        Log::info('GoogleAdsExecutionAgent: Creating Performance Max Campaign in account', [
            'customer_id' => $customerId,
        ]);

        // 1. Create Campaign — idempotency guard prevents duplicates on retry.
        // This was the source of 6 duplicate PMax campaigns created on 2026-05-19
        // when the deployment job was retried without checking for an existing campaign.
        $timestamp = now()->format('Ymd_His');
        if ($reusableGoogleCampaign = $strategy->reusableGoogleCampaignId()) {
            $campaignResourceName = $reusableGoogleCampaign;
            Log::info('GoogleAdsExecutionAgent: Reusing existing PMax campaign from prior attempt — skipping creation to prevent duplicate', [
                'campaign_id' => $campaign->id,
                'google_ads_campaign_id' => $campaignResourceName,
            ]);
            $result->addPlatformId('campaign', $campaignResourceName);
        } else {
            $createCampaignService = new CreatePerformanceMaxCampaign($this->customer);
            $campaignName = $campaign->name.' - PMax - '.$timestamp;

            $campaignData = [
                'businessName' => $campaignName,
                'budget' => $strategy->daily_budget ?: ($campaign->daily_budget ?: $campaign->total_budget / 30),
                ...GoogleCampaignSchedule::for($campaign),
            ];

            $campaignResourceName = ($createCampaignService)($customerId, $campaignData);
            if (! $campaignResourceName) {
                throw new \Exception('Failed to create Performance Max campaign');
            }

            $result->addPlatformId('campaign', $campaignResourceName);
            $strategy->recordGoogleCampaignId($campaignResourceName);
        }

        $reader = app(ReadCampaignConfiguration::class, ['customer' => $this->customer]);
        $stage = $reader->performanceMax($customerId, $campaignResourceName);
        if (($stage['campaign']['campaign']['status'] ?? null) !== 'PAUSED'
            || ($stage['campaign']['campaign']['advertisingChannelType'] ?? null) !== 'PERFORMANCE_MAX') {
            $result->addError('pmax_paused_stage_required', 'Google must confirm a paused Performance Max campaign before creating or updating its ads.');

            return;
        }
        $verified = $goals->reconcileStagedPerformanceMax($strategy, $campaignResourceName);
        if (($verified['configuration_verified'] ?? false) !== true
            || ($verified['intent']['action_resource'] ?? null) !== $intent['action_resource']) {
            $result->addError('pmax_conversion_goal_unverified', 'Google did not confirm this campaign is bidding on the reviewed conversion action.');

            return;
        }

        // 1.5 Add Location Targeting
        $this->geo->addLocationTargeting($customerId, $campaignResourceName, $campaign, $strategy, $plan, $result);

        // 2. Prepare Assets
        $assets = [];
        $createTextAssetService = new CreateTextAsset($this->customer);
        $uploadImageService = new UploadImageAsset($this->customer);

        // Use the reviewed business name and each copy asset in its own field.
        $textAssets[AssetFieldType::BUSINESS_NAME] = [mb_substr($businessName, 0, 25)];
        foreach ($textAssets as $field => $lines) {
            foreach ($lines as $line) {
                $assetResourceName = ($createTextAssetService)($customerId, $line);
                if ($assetResourceName) {
                    $assets[] = ['asset' => $assetResourceName, 'field_type' => $field];
                } else {
                    $result->addWarning('pmax_text_asset_unavailable', 'Google Ads could not register one of the reviewed Performance Max text assets.');
                }
            }
        }

        // 2.2 Image Assets
        $hasLogo = false;

        $approvedLogos = $imageCollaterals->filter(fn (ImageCollateral $image) => isset($reviewedLogos[$image->id]));
        $marketingImages = $imageCollaterals->reject(fn (ImageCollateral $image) => PerformanceMaxLogo::designated($image));
        foreach ($approvedLogos->take(5)->concat($marketingImages->take(15)) as $image) {
            try {
                $imageData = StorageHelper::get($image->s3_path);
                if (! $imageData) {
                    Log::warning('GoogleAdsExecutionAgent: Image data is null, skipping upload', [
                        's3_path' => $image->s3_path,
                    ]);

                    continue;
                }
                if ($strategy->creative_approval !== null && ! app(CreativeRelease::class)->matchesMedia($strategy, 'image', $image->id, $imageData)) {
                    $result->addError('pmax_image_changed', 'A selected image no longer matches the customer-approved render.');

                    return;
                }

                $field = PerformanceMaxLogo::imageField($image, $strategy, $imageData);
                if ($field === null) {
                    $result->addWarning('pmax_image_format_unsupported', 'A reviewed image did not meet the Performance Max size or format requirements.');

                    continue;
                }

                $assetResourceName = ($uploadImageService)($customerId, $imageData, $image->s3_path);
                if (! $assetResourceName) {
                    $result->addWarning('pmax_image_asset_unavailable', 'Google Ads could not register a reviewed Performance Max image.');

                    continue;
                }
                $assets[] = ['asset' => $assetResourceName, 'field_type' => $field];
                if ($field === AssetFieldType::LOGO) {
                    $hasLogo = true;
                }
            } catch (\Throwable $e) {
                report($e);
                $result->addWarning("Failed to upload/link image asset {$image->s3_path}: ".$e->getMessage());
            }
        }

        if (! $hasLogo) {
            $result->addError('pmax_business_logo_unavailable', 'Google could not register the customer-approved business logo. Check the selected logo and retry the paused deployment.');

            return;
        }

        // 2.3 Video Assets — collected separately and linked AFTER the asset group
        // is created, so a short/invalid video can't abort the whole batch.
        $uploadVideoAssetService = new UploadVideoAsset($this->customer);
        $videoCollaterals = VideoCollateral::forDeployment($strategy)
            ->where('status', 'completed')
            ->whereNotNull('youtube_video_id')
            ->limit(15)
            ->get();
        $videosWithoutYouTubeId = VideoCollateral::forDeployment($strategy)
            ->where('status', 'completed')
            ->whereNull('youtube_video_id')
            ->count();

        // Pre-register video assets (upload step only — linking happens after group creation)
        $videoAssetResourceNames = [];
        foreach ($videoCollaterals as $video) {
            try {
                $assetResourceName = ($uploadVideoAssetService)($customerId, $video->youtube_video_id, "Video Asset #{$video->id}");
                if ($assetResourceName) {
                    $videoAssetResourceNames[] = $assetResourceName;
                } else {
                    $result->addWarning('pmax_video_asset_unavailable', 'Google Ads could not register one of the reviewed video assets.');
                }
            } catch (\Throwable $e) {
                report($e);
                $result->addWarning("Failed to register video asset {$video->id}: ".$e->getMessage());
            }
        }

        if ($videosWithoutYouTubeId > 0) {
            $result->addWarning('videos_pending_youtube', "{$videosWithoutYouTubeId} video(s) have no YouTube ID yet — they will be linked automatically once uploaded. Run: php artisan pmax:repair-assets --strategy={$strategy->id}");
        }

        // 3. Create Asset Group with text + image assets only (no videos)
        // Videos are linked individually below so a short/rejected video skips gracefully.
        $createAssetGroupService = new CreateAssetGroupWithAssets($this->customer);
        $assetGroupName = 'Reviewed asset group '.$strategy->id;
        $finalUrl = $this->urls->getFinalUrl($campaign, $strategy, $plan);

        if (! $finalUrl) {
            throw new \Exception('No landing page URL found for Asset Group creation.');
        }

        $assetGroup = collect(array_column($stage['groups'], 'assetGroup'))->firstWhere('name', $assetGroupName);
        if ($assetGroup && (($assetGroup['status'] ?? null) !== 'ENABLED' || $assetGroup['finalUrls'] !== [$finalUrl])) {
            throw new \RuntimeException('The existing Performance Max asset group differs from the reviewed destination.');
        }
        if ($reader->campaignStatus($customerId, $campaignResourceName) !== 'PAUSED') {
            throw new \RuntimeException('Google no longer confirms paused staging for the Performance Max assets.');
        }
        $nativeCta = GoogleCreativeCta::approvedLabel($strategy);
        if ($assetGroup && $nativeCta !== null) {
            $existingCtas = PerformanceMaxLaunchCheck::linkedCtas($stage, $assetGroup['resourceName']);
            if ($existingCtas === []) {
                $createAssetGroupService->attachCallToAction($customerId, $assetGroup['resourceName'], $nativeCta);
            } elseif ($existingCtas !== [CallToActionType::name(GoogleCreativeCta::type($nativeCta))]) {
                throw new \RuntimeException('The staged Performance Max call to action differs from the customer approval.');
            }
        }
        $assetGroupResourceName = $assetGroup['resourceName'] ?? ($createAssetGroupService)($customerId, $campaignResourceName, $assetGroupName, [$finalUrl], $assets, $nativeCta);
        if (! $assetGroupResourceName) {
            throw new \Exception('Failed to create Asset Group');
        }

        // 3b. Link video assets individually — skip any that Google rejects (e.g. too short)
        $linkAssetGroupService = new LinkAssetGroupAsset($this->customer);
        foreach ($videoAssetResourceNames as $videoAssetResource) {
            try {
                if (! ($linkAssetGroupService)($customerId, $assetGroupResourceName, $videoAssetResource, AssetFieldType::YOUTUBE_VIDEO)) {
                    $result->addWarning('pmax_video_link_failed', 'Google Ads did not link one of the reviewed video assets.');

                    continue;
                }
                Log::info('GoogleAdsExecutionAgent: Linked video asset to PMax asset group', [
                    'asset_group' => $assetGroupResourceName,
                    'video_asset' => $videoAssetResource,
                ]);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('GoogleAdsExecutionAgent: Skipping video asset (rejected by Google): '.$e->getMessage(), [
                    'video_asset' => $videoAssetResource,
                ]);
                $result->addWarning('Video asset skipped: '.$e->getMessage());
            }
        }

        $result->addPlatformId('asset_group', $assetGroupResourceName);
        $themes = AddAudienceSignals::themes($strategy->targetingConfig?->google_options['search_themes'] ?? []);
        if ($themes !== []) {
            $signals = app(AddAudienceSignals::class, ['customer' => $this->customer]);
            if (! $signals->ensureSearchThemes($customerId, $assetGroupResourceName, $themes)) {
                $result->addWarning('pmax_search_themes_unconfirmed', 'Google has not confirmed all the reviewed Performance Max search themes.');
            }
        }

        // Dispatch async job to upload any videos that don't have YouTube IDs yet
        if ($videosWithoutYouTubeId > 0) {
            \App\Jobs\UploadPMaxVideoAssets::dispatch($strategy->id, $customerId, $assetGroupResourceName)
                ->delay(now()->addSeconds(30));
        }

        // 4. Add Ad Extensions (Sitelinks, Callouts) - PMax can use campaign-level assets
        $this->extensions->createAndLinkAdExtensions($customerId, $campaignResourceName, $strategy, $result);

        $result->addMetadata('google_pmax_staging', ['campaign_resource' => $campaignResourceName,
            'asset_group' => $assetGroupResourceName, 'final_urls' => [$finalUrl], 'native_cta' => $nativeCta,
            'goal_action' => $intent['action_resource'], 'budget_micros' => \App\Services\Campaigns\CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget)]);
    }

    /**
     * Execute Video campaign deployment
     */
}
