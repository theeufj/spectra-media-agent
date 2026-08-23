<?php

namespace App\Services\Agents\Google\Executors;

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
use App\Services\Agents\Google\LandingUrlBuilder;
use App\Services\GoogleAds\CommonServices\CreateTextAsset;
use App\Services\GoogleAds\DisplayServices\UploadImageAsset;
use App\Services\GoogleAds\PerformanceMaxServices\CreateAssetGroupWithAssets;
use App\Services\GoogleAds\PerformanceMaxServices\CreatePerformanceMaxCampaign;
use App\Services\GoogleAds\PerformanceMaxServices\LinkAssetGroupAsset;
use App\Services\GoogleAds\VideoServices\UploadVideoAsset;
use App\Services\StorageHelper;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;
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
        if ($this->customer->google_ads_customer_id && $customerId !== $this->customer->google_ads_customer_id) {
            Log::warning('GoogleAdsExecutionAgent: Customer ID mismatch, switching to stored account ID', [
                'provided_id' => $customerId,
                'stored_id' => $this->customer->google_ads_customer_id,
            ]);
            $customerId = $this->customer->google_ads_customer_id;
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

            // Only set a Target CPA if the account has enough conversion history.
            // Below 50 conversions, PMax uses Maximize Conversions (no target).
            // BiddingStrategyProgressionAgent will add the target once data matures.
            $conversionCount = $this->bidding->getConversionCountForCustomer($customerId);
            $targetCpaMicros = null;
            if ($strategy->cpa_target && $conversionCount >= 50) {
                $targetCpaMicros = $strategy->cpa_target;
            } elseif ($strategy->cpa_target && $conversionCount < 50) {
                $result->addWarning('pmax_bidding_downgraded', "Performance Max will use Maximize Conversions until 50 conversions are recorded ({$conversionCount} so far). Target CPA will be applied automatically by the Bidding Progression Agent.");
            }

            $campaignData = [
                'businessName' => $campaignName,
                'budget' => $strategy->daily_budget ?: ($campaign->daily_budget ?: $campaign->total_budget / 30),
                'startDate' => now()->addDay()->format('Y-m-d'),
                'endDate' => now()->addYear()->format('Y-m-d'),
                'targetCpaMicros' => $targetCpaMicros,
            ];

            $campaignResourceName = ($createCampaignService)($customerId, $campaignData);
            if (! $campaignResourceName) {
                throw new \Exception('Failed to create Performance Max campaign');
            }

            $result->addPlatformId('campaign', $campaignResourceName);
            $strategy->recordGoogleCampaignId($campaignResourceName);
        }

        // 1.5 Add Location Targeting
        $this->geo->addLocationTargeting($customerId, $campaignResourceName, $campaign, $strategy, $plan, $result);

        // 2. Prepare Assets
        $assets = [];
        $createTextAssetService = new CreateTextAsset($this->customer);
        $uploadImageService = new UploadImageAsset($this->customer);

        // 2.1 Text Assets
        $adCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->first();

        if ($adCopy) {
            // Headlines (Min 3, Max 5)
            $headlines = array_slice($adCopy->headlines ?? [], 0, 5);
            foreach ($headlines as $headline) {
                $assetResourceName = ($createTextAssetService)($customerId, $headline);
                if ($assetResourceName) {
                    $assets[] = ['asset' => $assetResourceName, 'field_type' => AssetFieldType::HEADLINE];
                }
            }

            // Long Headlines (Min 1, Max 5)
            $longHeadline = $headlines[0] ?? 'Discover Our Amazing Products';
            $assetResourceName = ($createTextAssetService)($customerId, $longHeadline);
            if ($assetResourceName) {
                $assets[] = ['asset' => $assetResourceName, 'field_type' => AssetFieldType::LONG_HEADLINE];
            }

            // Descriptions (Min 2, Max 5)
            $descriptions = array_slice($adCopy->descriptions ?? [], 0, 5);
            foreach ($descriptions as $description) {
                $assetResourceName = ($createTextAssetService)($customerId, $description);
                if ($assetResourceName) {
                    $assets[] = ['asset' => $assetResourceName, 'field_type' => AssetFieldType::DESCRIPTION];
                }
            }

            // Business Name (Min 1, Max 1) — max 25 chars
            $rawBusinessName = $this->customer->name ?? 'ShopFree';
            $businessName = mb_substr($rawBusinessName, 0, 25);
            $assetResourceName = ($createTextAssetService)($customerId, $businessName);
            if ($assetResourceName) {
                $assets[] = ['asset' => $assetResourceName, 'field_type' => AssetFieldType::BUSINESS_NAME];
            }
        }

        // 2.2 Image Assets
        $imageCollaterals = ImageCollateral::forStrategy($strategy)->where('is_active', true)->where('should_deploy', true)->limit(15)->get();
        $hasLogo = false;
        $firstSquareAsset = null; // track for logo fallback

        foreach ($imageCollaterals as $image) {
            try {
                $imageData = StorageHelper::get($image->s3_path);
                if (! $imageData) {
                    Log::warning('GoogleAdsExecutionAgent: Image data is null, skipping upload', [
                        's3_path' => $image->s3_path,
                    ]);

                    continue;
                }

                $assetResourceName = ($uploadImageService)($customerId, $imageData, $image->s3_path);

                if ($assetResourceName) {
                    if (str_contains(strtolower($image->s3_path), 'logo')) {
                        $assets[] = ['asset' => $assetResourceName, 'field_type' => AssetFieldType::LOGO];
                        $hasLogo = true;
                    } else {
                        $size = @getimagesizefromstring($imageData);
                        $width = $size[0] ?? 0;
                        $height = $size[1] ?? 1;
                        $ratio = $height > 0 ? $width / $height : 1;

                        // Google PMax accepted ratios (±5% tolerance):
                        //   MARKETING_IMAGE:        1.91:1  (landscape) — ratio 1.8145–2.0055, min 600×314
                        //   SQUARE_MARKETING_IMAGE: 1:1     (square)    — ratio 0.95–1.05,     min 300×300
                        $is191 = $ratio >= 1.8145 && $ratio <= 2.0055 && $width >= 600 && $height >= 314;
                        $isSquare = $ratio >= 0.95 && $ratio <= 1.05 && $width >= 300 && $height >= 300;
                        if ($is191) {
                            $assets[] = ['asset' => $assetResourceName, 'field_type' => AssetFieldType::MARKETING_IMAGE];
                        } elseif ($isSquare) {
                            $assets[] = ['asset' => $assetResourceName, 'field_type' => AssetFieldType::SQUARE_MARKETING_IMAGE];
                            $firstSquareAsset ??= $assetResourceName;
                        } else {
                            Log::info("GoogleAdsExecutionAgent: Skipping image asset — dimensions {$width}×{$height} (ratio {$ratio}) don't meet PMax ratio requirements", [
                                's3_path' => $image->s3_path,
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                $result->addWarning("Failed to upload/link image asset {$image->s3_path}: ".$e->getMessage());
            }
        }

        // Logo is required for PMax. Use the first square image as fallback (logos must be image assets).
        if (! $hasLogo && $firstSquareAsset) {
            $assets[] = ['asset' => $firstSquareAsset, 'field_type' => AssetFieldType::LOGO];
            Log::warning('GoogleAdsExecutionAgent: No explicit logo found, using first square image as logo fallback.');
        } elseif (! $hasLogo) {
            Log::warning('GoogleAdsExecutionAgent: No logo or square image available — asset group may be rejected by Google.');
        }

        // 2.3 Video Assets — collected separately and linked AFTER the asset group
        // is created, so a short/invalid video can't abort the whole batch.
        $uploadVideoAssetService = new UploadVideoAsset($this->customer);
        $videoCollaterals = VideoCollateral::where('campaign_id', $campaign->id)
            ->where('is_active', true)
            ->whereNotNull('youtube_video_id')
            ->get();
        $videosWithoutYouTubeId = VideoCollateral::where('campaign_id', $campaign->id)
            ->where('is_active', true)
            ->whereNull('youtube_video_id')
            ->count();

        // Pre-register video assets (upload step only — linking happens after group creation)
        $videoAssetResourceNames = [];
        foreach ($videoCollaterals as $video) {
            try {
                $assetResourceName = ($uploadVideoAssetService)($customerId, $video->youtube_video_id, "Video Asset #{$video->id}");
                if ($assetResourceName) {
                    $videoAssetResourceNames[] = $assetResourceName;
                }
            } catch (\Exception $e) {
                $result->addWarning("Failed to register video asset {$video->id}: ".$e->getMessage());
            }
        }

        if ($videosWithoutYouTubeId > 0) {
            $result->addWarning('videos_pending_youtube', "{$videosWithoutYouTubeId} video(s) have no YouTube ID yet — they will be linked automatically once uploaded. Run: php artisan pmax:repair-assets --strategy={$strategy->id}");
        }

        // 3. Create Asset Group with text + image assets only (no videos)
        // Videos are linked individually below so a short/rejected video skips gracefully.
        $createAssetGroupService = new CreateAssetGroupWithAssets($this->customer);
        $assetGroupName = 'Asset Group - '.$timestamp;
        $finalUrl = $this->urls->getFinalUrl($campaign, $strategy, $plan);

        if (! $finalUrl) {
            throw new \Exception('No landing page URL found for Asset Group creation.');
        }

        $assetGroupResourceName = ($createAssetGroupService)($customerId, $campaignResourceName, $assetGroupName, [$finalUrl], $assets);
        if (! $assetGroupResourceName) {
            throw new \Exception('Failed to create Asset Group');
        }

        // 3b. Link video assets individually — skip any that Google rejects (e.g. too short)
        $linkAssetGroupService = new LinkAssetGroupAsset($this->customer);
        foreach ($videoAssetResourceNames as $videoAssetResource) {
            try {
                ($linkAssetGroupService)($customerId, $assetGroupResourceName, $videoAssetResource, AssetFieldType::YOUTUBE_VIDEO);
                Log::info('GoogleAdsExecutionAgent: Linked video asset to PMax asset group', [
                    'asset_group' => $assetGroupResourceName,
                    'video_asset' => $videoAssetResource,
                ]);
            } catch (\Exception $e) {
                Log::warning('GoogleAdsExecutionAgent: Skipping video asset (rejected by Google): '.$e->getMessage(), [
                    'video_asset' => $videoAssetResource,
                ]);
                $result->addWarning('Video asset skipped: '.$e->getMessage());
            }
        }

        $result->addPlatformId('asset_group', $assetGroupResourceName);

        // Dispatch async job to upload any videos that don't have YouTube IDs yet
        if ($videosWithoutYouTubeId > 0) {
            \App\Jobs\UploadPMaxVideoAssets::dispatch($strategy->id, $customerId, $assetGroupResourceName)
                ->delay(now()->addSeconds(30));
        }

        // 4. Add Ad Extensions (Sitelinks, Callouts) - PMax can use campaign-level assets
        $this->extensions->createAndLinkAdExtensions($customerId, $campaignResourceName, $strategy, $result);

        // 5. Apply conversion value rules (device + audience modifiers)
        try {
            $applyValueRules = new \App\Services\GoogleAds\CommonServices\ApplyConversionValueRules($this->customer);
            $applyValueRules($customerId, $campaignResourceName, $this->customer);
        } catch (\Exception $e) {
            Log::warning('GoogleAdsExecutionAgent: Conversion value rules not applied: '.$e->getMessage());
        }
    }

    /**
     * Execute Video campaign deployment
     */
}
