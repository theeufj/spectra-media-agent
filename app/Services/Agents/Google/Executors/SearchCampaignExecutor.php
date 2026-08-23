<?php

namespace App\Services\Agents\Google\Executors;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\AdExtensionBuilder;
use App\Services\Agents\Google\AudienceTargeter;
use App\Services\Agents\Google\BiddingStrategyApplier;
use App\Services\Agents\Google\GeoTargetResolver;
use App\Services\Agents\Google\LandingUrlBuilder;
use App\Services\Agents\Google\SearchKeywordBuilder;
use App\Services\GoogleAds\CommonServices\LinkAdGroupAsset;
use App\Services\GoogleAds\DisplayServices\UploadImageAsset;
use App\Services\GoogleAds\SearchServices\CreateResponsiveSearchAd;
use App\Services\GoogleAds\SearchServices\CreateSearchAdGroup;
use App\Services\GoogleAds\SearchServices\CreateSearchCampaign;
use App\Services\StorageHelper;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;
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
        // Verify we are targeting the correct account
        if ($this->customer->google_ads_customer_id && $customerId !== $this->customer->google_ads_customer_id) {
            Log::warning('GoogleAdsExecutionAgent: Customer ID mismatch, switching to stored account ID', [
                'provided_id' => $customerId,
                'stored_id' => $this->customer->google_ads_customer_id,
            ]);
            $customerId = $this->customer->google_ads_customer_id;
        }

        Log::info('GoogleAdsExecutionAgent: Creating Search Campaign in account', [
            'customer_id' => $customerId,
        ]);

        // 1. Create Campaign — idempotency guard prevents duplicates on retry
        $timestamp = now()->format('Ymd_His');
        if (! empty($campaign->google_ads_campaign_id)) {
            $campaignResourceName = $campaign->google_ads_campaign_id;
            Log::info('GoogleAdsExecutionAgent: Reusing existing Search campaign from prior attempt', [
                'campaign_id' => $campaign->id,
                'google_ads_campaign_id' => $campaignResourceName,
            ]);
            $result->addPlatformId('campaign', $campaignResourceName);
        } else {
            $createCampaignService = new CreateSearchCampaign($this->customer);
            $campaignName = $campaign->name.' - '.$timestamp;

            $campaignData = [
                'businessName' => $campaignName,
                'budget' => $strategy->daily_budget ?: ($campaign->daily_budget ?: $campaign->total_budget / 30),
                'startDate' => now()->addDay()->format('Y-m-d'),
                'endDate' => now()->addYear()->format('Y-m-d'),
            ];

            $campaignResourceName = ($createCampaignService)($customerId, $campaignData);
            if (! $campaignResourceName) {
                throw new \Exception('Failed to create search campaign');
            }

            $result->addPlatformId('campaign', $campaignResourceName);
            $campaign->google_ads_campaign_id = $campaignResourceName;
            $campaign->save();
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
            $createAdGroupService = new CreateSearchAdGroup($this->customer);
            $adGroupName = 'Default Ad Group - '.$timestamp;
            $adGroupResourceName = ($createAdGroupService)($customerId, $campaignResourceName, $adGroupName);
            if (! $adGroupResourceName) {
                throw new \Exception('Failed to create search ad group');
            }

            $result->addPlatformId('ad_group', $adGroupResourceName);
            $strategy->google_ads_ad_group_id = $adGroupResourceName;
            $strategy->save();
        }

        // 3. Add Keywords — always validate through Keyword Planner for volume data
        $keywords = $this->keywords->getKeywords($campaign, $strategy, $plan);
        if (empty($keywords)) {
            // Fallback: use AI keyword research to generate initial keywords
            $keywords = $this->keywords->researchKeywords($customerId, $campaign, $strategy);
        }
        // Validate all keywords through Keyword Planner to filter low-volume terms
        if (! empty($keywords)) {
            $keywords = $this->keywords->validateAndEnrichKeywords($customerId, $keywords, $campaign, $strategy);
        }
        if (! empty($keywords)) {
            $this->keywords->addKeywords($customerId, $adGroupResourceName, $keywords, $result);
        }

        // 3.2 Add negative keywords at campaign creation time
        $this->keywords->addInitialNegativeKeywords($customerId, $campaignResourceName, $campaign, $strategy, $result);

        // 3.5 Add Audience Targeting
        $this->audiences->addAudienceTargeting($customerId, $adGroupResourceName, $strategy, $result);

        // 4. Upload Image Assets for Responsive Search Ad (if available)
        $imageAssetResourceNames = [];
        $imageCollaterals = ImageCollateral::forStrategy($strategy)->where('is_active', true)->where('should_deploy', true)->limit(15)->get();
        if ($imageCollaterals->isNotEmpty()) {
            $uploadImageAssetService = new UploadImageAsset($this->customer);
            $linkAdGroupAssetService = new LinkAdGroupAsset($this->customer);

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

                        // Determine field type from image dimensions.
                        // AD_IMAGE is Display/Video only — Search image extensions require
                        // MARKETING_IMAGE (landscape) or SQUARE_MARKETING_IMAGE (square).
                        $size = @getimagesizefromstring($imageData);
                        $width = $size[0] ?? 0;
                        $height = $size[1] ?? 1;
                        $ratio = $height > 0 ? $width / $height : 1;
                        $fieldType = ($ratio >= 0.8 && $ratio < 1.5 && $width >= 300 && $height >= 300)
                            ? AssetFieldType::SQUARE_MARKETING_IMAGE
                            : AssetFieldType::MARKETING_IMAGE;

                        $linkResourceName = ($linkAdGroupAssetService)($customerId, $adGroupResourceName, $assetResourceName, $fieldType);
                        if ($linkResourceName) {
                            $result->addPlatformId('ad_group_asset', $linkResourceName);
                            Log::info('GoogleAdsExecutionAgent: Linked image asset to ad group', [
                                'asset' => $assetResourceName,
                                'ad_group' => $adGroupResourceName,
                                'field_type' => $fieldType,
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    $result->addWarning("Failed to upload/link image asset {$image->s3_path}: ".$e->getMessage());
                }
            }
        }

        // 5. Create Responsive Search Ads (2-3 variants per Google best practices)
        $adCopies = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%google%'])->limit(3)->get();
        if ($adCopies->isEmpty()) {
            $adCopies = $strategy->adCopies()->limit(3)->get();
        }

        if ($adCopies->isNotEmpty()) {
            $finalUrl = $this->urls->getFinalUrl($campaign, $strategy, $plan);

            if (! $finalUrl) {
                $result->addWarning('No landing page URL found for ad creation. Skipping ad creation.');
            } else {
                $createAdService = new CreateResponsiveSearchAd($this->customer);

                foreach ($adCopies as $adCopy) {
                    $adData = [
                        'finalUrls' => [$finalUrl],
                        'headlines' => $adCopy->headlines ?? [],
                        'descriptions' => $adCopy->descriptions ?? [],
                        'imageAssets' => $imageAssetResourceNames,
                    ];
                    $adResourceName = ($createAdService)($customerId, $adGroupResourceName, $adData);
                    if ($adResourceName) {
                        $result->addPlatformId('ad', $adResourceName);
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
                            'imageAssets' => $imageAssetResourceNames,
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

        // 7. Apply bidding strategy from strategy record, with a safety guard:
        // Target CPA requires 30+/month, Target ROAS requires 50+/month — fall back to
        // MaximizeConversions on accounts below those thresholds.
        $this->bidding->applyBiddingStrategy($customerId, $campaignResourceName, $strategy, $result);

        // 8. Apply conversion value rules (device + audience modifiers)
        try {
            $applyValueRules = new \App\Services\GoogleAds\CommonServices\ApplyConversionValueRules($this->customer);
            $applyValueRules($customerId, $campaignResourceName, $this->customer);
        } catch (\Exception $e) {
            Log::warning('GoogleAdsExecutionAgent: Conversion value rules not applied: '.$e->getMessage());
        }
    }

    /**
     * Apply the strategy's recommended bidding strategy to a campaign.
     * Automatically downgrades Smart Bidding strategies to MaximizeConversions
     * when the account has insufficient conversion history.
     */
}
