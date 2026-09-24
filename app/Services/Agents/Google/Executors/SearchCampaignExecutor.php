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
use App\Services\GoogleAds\DisplayServices\UploadImageAsset;
use App\Services\GoogleAds\SearchServices\CreateResponsiveSearchAd;
use App\Services\GoogleAds\SearchServices\CreateSearchAdGroup;
use App\Services\GoogleAds\SearchServices\CreateSearchCampaign;
use App\Services\StorageHelper;
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
        if ($reusableGoogleCampaign = $strategy->reusableGoogleCampaignId()) {
            $campaignResourceName = $reusableGoogleCampaign;
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
            $strategy->recordGoogleCampaignId($campaignResourceName);
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
                $createAdService = new CreateResponsiveSearchAd($this->customer);

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

        // 7. Apply bidding strategy from strategy record, with a safety guard:
        // Target CPA requires 30+/month, Target ROAS requires 50+/month — fall back to
        // MaximizeConversions on accounts below those thresholds.
        $this->bidding->applyBiddingStrategy($customerId, $campaignResourceName, $strategy, $result);

        $resources = $result->metadata['platform_resources'] ?? [];
        $extensionTypes = ['sitelink_asset', 'callout_asset', 'structured_snippet_asset', 'call_asset', 'price_asset', 'promotion_asset'];
        $goal = strtoupper(str_replace(['-', ' '], '_', $strategy->conversion_goals['primary_goal'] ?? ''));
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
            'conversion_category' => match ($goal) {
                'PURCHASE', 'PURCHASES', 'SALE', 'SALES', 'PAID_SUBSCRIPTION' => 'PURCHASE',
                'SIGNUP', 'SIGN_UP', 'SIGN_UPS', 'SIGN_UPS/REGISTRATIONS' => 'SIGNUP',
                'LEAD', 'LEADS', 'SUBMIT_LEAD_FORM' => 'SUBMIT_LEAD_FORM',
                default => null,
            },
        ]);

        // 8. Apply conversion value rules (device + audience modifiers)
        try {
            $applyValueRules = new \App\Services\GoogleAds\CommonServices\ApplyConversionValueRules($this->customer);
            $applyValueRules($customerId, $campaignResourceName, $this->customer);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('GoogleAdsExecutionAgent: Conversion value rules not applied: '.$e->getMessage());
        }
    }

    /**
     * Apply the strategy's recommended bidding strategy to a campaign.
     * Automatically downgrades Smart Bidding strategies to MaximizeConversions
     * when the account has insufficient conversion history.
     */
}
