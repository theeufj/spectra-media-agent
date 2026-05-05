<?php

namespace App\Services\Agents\Optimization;

use App\Models\AgentActivity;
use App\Models\Audience;
use App\Models\Campaign;
use App\Services\FacebookAds\CampaignService as FacebookCampaignService;
use App\Services\FacebookAds\CustomAudienceService as FacebookCustomAudienceService;
use App\Services\MicrosoftAds\AdGroupService as MicrosoftAdGroupService;
use App\Services\MicrosoftAds\CampaignService as MicrosoftCampaignService;
use App\Services\GoogleAds\CommonServices\CreateCallAsset;
use App\Services\GoogleAds\CommonServices\CreatePriceAsset;
use App\Services\GoogleAds\CommonServices\CreatePromotionAsset;
use App\Services\GoogleAds\CommonServices\CreateRemarketingAudience;
use App\Services\GoogleAds\CommonServices\CreateStructuredSnippetAsset;
use App\Services\GoogleAds\CommonServices\CreateUserList;
use App\Services\GoogleAds\CommonServices\LinkCampaignAsset;
use App\Services\GoogleAds\CommonServices\RemoveKeyword;
use App\Services\GoogleAds\CommonServices\SetAdSchedule;
use App\Services\GoogleAds\CommonServices\SetDeviceBidAdjustment;
use App\Services\GoogleAds\CommonServices\SetLocationBidAdjustment;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBudget;
use App\Services\GoogleAds\CommonServices\UpdateKeywordBid;
use App\Services\GoogleAds\CommonServices\UpdateKeywordStatus;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

/**
 * Applies a single AI recommendation to the relevant Google Ads API endpoints.
 * Gated behind the auto_optimization feature flag per customer.
 */
class RecommendationApplier
{
    public function apply(Campaign $campaign, array $recommendation): array
    {
        $customer = $campaign->customer;

        if ($customer && !Feature::for($customer)->active('auto_optimization')) {
            return [
                'applied'        => false,
                'message'        => 'Auto-optimization is disabled for this customer',
                'recommendation' => $recommendation,
            ];
        }

        $type = $recommendation['type'] ?? null;

        if (!$type) {
            return ['applied' => false, 'message' => 'Recommendation type is missing', 'recommendation' => $recommendation];
        }

        try {
            return match ($type) {
                'BUDGET'       => $this->applyBudget($campaign, $recommendation),
                'KEYWORDS'     => $this->applyKeyword($campaign, $recommendation),
                'BIDDING'      => $this->applyBidding($campaign, $recommendation),
                'TARGETING'    => $this->applyTargeting($campaign, $recommendation),
                'AD_EXTENSIONS' => $this->applyExtension($campaign, $recommendation),
                'SCHEDULE'     => $this->applySchedule($campaign, $recommendation),
                'AUDIENCE'     => $this->applyAudience($campaign, $recommendation),
                default        => ['applied' => false, 'message' => "Auto-apply not yet supported for type: {$type}", 'recommendation' => $recommendation],
            };
        } catch (\Exception $e) {
            Log::error("RecommendationApplier: Failed to apply recommendation", [
                'campaign_id'    => $campaign->id,
                'recommendation' => $recommendation,
                'error'          => $e->getMessage(),
            ]);
            return ['applied' => false, 'message' => 'Failed to apply: ' . $e->getMessage(), 'recommendation' => $recommendation];
        }
    }

    private function applyBudget(Campaign $campaign, array $rec): array
    {
        $newBudget = $rec['suggested_value'] ?? null;

        if (!$newBudget || $newBudget <= 0) {
            return ['applied' => false, 'message' => 'Invalid budget value', 'recommendation' => $rec];
        }

        $oldBudget         = $campaign->daily_budget;
        $campaign->daily_budget = $newBudget;
        $campaign->save();

        $customer = $campaign->customer;

        if ($campaign->google_ads_campaign_id && $customer) {
            try {
                $customerId = $customer->cleanGoogleCustomerId();
                $resource   = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";
                (new UpdateCampaignBudget($customer))($customerId, $resource, (int) ($newBudget * 1_000_000));
            } catch (\Exception $e) {
                Log::warning("RecommendationApplier: Google budget API update failed: " . $e->getMessage());
            }
        } elseif ($campaign->facebook_ads_campaign_id && $customer) {
            try {
                (new FacebookCampaignService($customer))->updateCampaign($campaign->facebook_ads_campaign_id, [
                    'daily_budget' => (int) ($newBudget * 100), // Facebook uses cents
                ]);
            } catch (\Exception $e) {
                Log::warning("RecommendationApplier: Facebook budget API update failed: " . $e->getMessage());
            }
        } elseif ($campaign->microsoft_ads_campaign_id && $customer) {
            try {
                (new MicrosoftCampaignService($customer))->updateBudget(
                    (string) $campaign->microsoft_ads_campaign_id,
                    (float) $newBudget
                );
            } catch (\Exception $e) {
                Log::warning("RecommendationApplier: Microsoft budget API update failed: " . $e->getMessage());
            }
        }

        return ['applied' => true, 'message' => "Budget adjusted from {$oldBudget} to {$newBudget}", 'recommendation' => $rec];
    }

    private function applyKeyword(Campaign $campaign, array $rec): array
    {
        // Microsoft: only keyword addition is supported via AdGroupService
        if ($campaign->microsoft_ads_campaign_id && !$campaign->google_ads_campaign_id) {
            return $this->applyMicrosoftKeyword($campaign, $rec);
        }

        $action   = $rec['direction'] ?? $rec['action'] ?? null;
        $resource = $rec['criterion_resource_name'] ?? null;
        $customer = $campaign->customer;

        if (!$customer || !$resource) {
            return ['applied' => false, 'message' => 'Missing customer or criterion resource name', 'recommendation' => $rec];
        }

        $customerId = $customer->cleanGoogleCustomerId();

        $result = match ($action) {
            'increase', 'decrease' => $this->adjustBid($customer, $customerId, $resource, $rec),
            'pause'  => $this->pauseKeyword($customer, $customerId, $resource),
            'enable' => $this->enableKeyword($customer, $customerId, $resource),
            'remove' => $this->removeKeyword($customer, $customerId, $resource),
            default  => ['applied' => false, 'message' => "Unknown keyword action: {$action}"],
        };

        $result['recommendation'] = $rec;
        return $result;
    }

    private function adjustBid($customer, string $customerId, string $resource, array $rec): array
    {
        $bid = $rec['suggested_value'] ?? null;
        if (!$bid) {
            return ['applied' => false, 'message' => 'No suggested bid value'];
        }
        $ok = (new UpdateKeywordBid($customer))($customerId, $resource, (int) $bid);
        return ['applied' => $ok, 'message' => $ok ? "Bid adjusted to {$bid} micros" : 'Failed to adjust bid'];
    }

    private function pauseKeyword($customer, string $customerId, string $resource): array
    {
        $ok = (new UpdateKeywordStatus($customer))->pause($customerId, $resource);
        return ['applied' => $ok, 'message' => $ok ? 'Keyword paused' : 'Failed to pause keyword'];
    }

    private function enableKeyword($customer, string $customerId, string $resource): array
    {
        $ok = (new UpdateKeywordStatus($customer))->enable($customerId, $resource);
        return ['applied' => $ok, 'message' => $ok ? 'Keyword enabled' : 'Failed to enable keyword'];
    }

    private function removeKeyword($customer, string $customerId, string $resource): array
    {
        $ok = (new RemoveKeyword($customer))($customerId, $resource);
        return ['applied' => $ok, 'message' => $ok ? 'Keyword removed' : 'Failed to remove keyword'];
    }

    private function applyBidding(Campaign $campaign, array $rec): array
    {
        $customer   = $campaign->customer;
        $subType    = $rec['sub_type'] ?? null;
        $confidence = $rec['confidence'] ?? 0;

        if ($subType === 'keyword_cpc' && $confidence >= 0.95 && $campaign->google_ads_campaign_id && $customer) {
            $kwResource  = $rec['keyword_resource'] ?? null;
            $newBidMicros = $rec['suggested_value'] ?? null;

            if ($kwResource && $newBidMicros) {
                $ok = (new UpdateKeywordBid($customer))(
                    $customer->cleanGoogleCustomerId(),
                    $kwResource,
                    (int) $newBidMicros
                );

                if ($ok) {
                    AgentActivity::record(
                        'optimization', 'bid_adjusted',
                        'Auto-adjusted keyword bid to $' . round($newBidMicros / 1_000_000, 2),
                        $customer->id, $campaign->id,
                        ['keyword' => $kwResource, 'new_bid_micros' => $newBidMicros, 'confidence' => $confidence]
                    );

                    return ['applied' => true, 'message' => 'Keyword bid adjusted to $' . round($newBidMicros / 1_000_000, 2), 'recommendation' => $rec];
                }
            }
        }

        return ['applied' => false, 'message' => 'Bidding strategy changes require manual review', 'recommendation' => $rec];
    }

    private function applyTargeting(Campaign $campaign, array $rec): array
    {
        $customer = $campaign->customer;

        if (!$customer || !$campaign->google_ads_campaign_id) {
            return ['applied' => false, 'message' => 'Device/location bid adjustments are Google Ads only — not applicable for this platform', 'recommendation' => $rec];
        }

        $customerId = $customer->cleanGoogleCustomerId();
        $resource   = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";
        $subType    = $rec['sub_type'] ?? null;

        $result = match ($subType) {
            'device'   => $this->deviceBid($customer, $customerId, $resource, $rec),
            'location' => $this->locationBid($customer, $customerId, $resource, $rec),
            default    => ['applied' => false, 'message' => "Unknown targeting sub_type: {$subType}"],
        };

        $result['recommendation'] = $rec;
        return $result;
    }

    private function deviceBid($customer, string $customerId, string $resource, array $rec): array
    {
        $device   = $rec['device_type'] ?? null;
        $modifier = $rec['suggested_value'] ?? null;
        if (!$device || $modifier === null) {
            return ['applied' => false, 'message' => 'Missing device type or bid modifier'];
        }
        $result = (new SetDeviceBidAdjustment($customer))($customerId, $resource, (int) $device, (float) $modifier);
        return ['applied' => $result !== null, 'message' => $result ? "Device bid set to {$modifier}x" : 'Failed to set device bid'];
    }

    private function locationBid($customer, string $customerId, string $resource, array $rec): array
    {
        $geo      = $rec['geo_target_constant'] ?? null;
        $modifier = $rec['suggested_value'] ?? null;
        if (!$geo || $modifier === null) {
            return ['applied' => false, 'message' => 'Missing geo target or bid modifier'];
        }
        $result = (new SetLocationBidAdjustment($customer))($customerId, $resource, $geo, (float) $modifier);
        return ['applied' => $result !== null, 'message' => $result ? "Location bid set to {$modifier}x for {$geo}" : 'Failed to set location bid'];
    }

    private function applySchedule(Campaign $campaign, array $rec): array
    {
        $customer = $campaign->customer;

        if (!$customer || !$campaign->google_ads_campaign_id) {
            return ['applied' => false, 'message' => 'Ad schedule adjustments are Google Ads only — not applicable for this platform', 'recommendation' => $rec];
        }

        $customerId   = $customer->cleanGoogleCustomerId();
        $resource     = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";
        $service      = new SetAdSchedule($customer);
        $scheduleType = $rec['sub_type'] ?? 'business_hours';

        if ($scheduleType === 'business_hours') {
            $modifier = (float) ($rec['suggested_value'] ?? 1.2);
            $results  = $service->setBusinessHours($customerId, $resource, $modifier);
            $applied  = count(array_filter($results)) > 0;
            return ['applied' => $applied, 'message' => $applied ? "Business hours schedule set ({$modifier}x)" : 'Failed to set business hours', 'recommendation' => $rec];
        }

        $result = ($service)(
            $customerId, $resource,
            (int) ($rec['day_of_week'] ?? 2),
            (int) ($rec['start_hour'] ?? 9), 2,
            (int) ($rec['end_hour'] ?? 17), 2,
            (float) ($rec['suggested_value'] ?? 1.0)
        );

        return ['applied' => $result !== null, 'message' => $result ? 'Ad schedule set' : 'Failed to set ad schedule', 'recommendation' => $rec];
    }

    private function applyExtension(Campaign $campaign, array $rec): array
    {
        $customer = $campaign->customer;

        if (!$customer || !$campaign->google_ads_campaign_id) {
            return ['applied' => false, 'message' => 'Missing customer or campaign Google ID', 'recommendation' => $rec];
        }

        $customerId = $customer->cleanGoogleCustomerId();
        $resource   = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";
        $type       = $rec['sub_type'] ?? null;

        if (!$type) {
            return ['applied' => false, 'message' => 'Missing extension sub_type', 'recommendation' => $rec];
        }

        try {
            [$assetResource, $fieldType, $label] = match ($type) {
                'structured_snippet' => $this->createSnippet($customer, $customerId, $rec),
                'call'               => $this->createCall($customer, $customerId, $rec),
                'price'              => $this->createPrice($customer, $customerId, $rec),
                'promotion'          => $this->createPromotion($customer, $customerId, $rec),
                default              => [null, null, $type],
            };

            if (!$assetResource) {
                return ['applied' => false, 'message' => "Failed to create {$type} extension", 'recommendation' => $rec];
            }

            $linkResult = (new LinkCampaignAsset($customer))($customerId, $resource, $assetResource, $fieldType);

            return ['applied' => $linkResult !== null, 'message' => $linkResult ? "{$label} created and linked" : "{$label} created but link failed", 'recommendation' => $rec];
        } catch (\Exception $e) {
            return ['applied' => false, 'message' => 'Error: ' . substr($e->getMessage(), 0, 200), 'recommendation' => $rec];
        }
    }

    private function createSnippet($customer, string $customerId, array $rec): array
    {
        $header = $rec['header'] ?? 'Services';
        $values = $rec['values'] ?? $rec['items'] ?? [];
        if (empty($values)) return [null, null, 'Structured Snippet'];
        $resource = (new CreateStructuredSnippetAsset($customer))($customerId, $header, $values);
        return [$resource, AssetFieldType::STRUCTURED_SNIPPET, 'Structured Snippet'];
    }

    private function createCall($customer, string $customerId, array $rec): array
    {
        $phone = $rec['phone_number'] ?? null;
        if (!$phone) return [null, null, 'Call'];
        $resource = (new CreateCallAsset($customer))($customerId, $phone, $rec['country_code'] ?? 'AU');
        return [$resource, AssetFieldType::CALL, 'Call'];
    }

    private function createPrice($customer, string $customerId, array $rec): array
    {
        $offerings = $rec['offerings'] ?? [];
        if (empty($offerings)) return [null, null, 'Price'];
        $resource = (new CreatePriceAsset($customer))($customerId, (int) ($rec['price_type'] ?? 8), (int) ($rec['price_qualifier'] ?? 2), $offerings);
        return [$resource, AssetFieldType::PRICE, 'Price'];
    }

    private function createPromotion($customer, string $customerId, array $rec): array
    {
        $target = $rec['promotion_target'] ?? null;
        $data   = $rec['promotion_data'] ?? [];
        if (!$target || empty($data)) return [null, null, 'Promotion'];
        $resource = (new CreatePromotionAsset($customer))($customerId, $target, $data);
        return [$resource, AssetFieldType::PROMOTION, 'Promotion'];
    }

    private function applyMicrosoftKeyword(Campaign $campaign, array $rec): array
    {
        $customer = $campaign->customer;
        $action   = $rec['direction'] ?? $rec['action'] ?? null;

        // Microsoft AdGroupService only supports adding new keywords, not bid changes or pausing
        if (!in_array($action, ['add', 'expand'], true)) {
            return ['applied' => false, 'message' => "Microsoft keyword '{$action}' not auto-applicable — requires manual action in Microsoft Ads", 'recommendation' => $rec];
        }

        $adGroupId = $rec['ad_group_id'] ?? null;
        $keyword   = $rec['keyword_text'] ?? $rec['text'] ?? null;
        $matchType = $rec['match_type'] ?? 'Broad';

        if (!$adGroupId || !$keyword || !$customer) {
            return ['applied' => false, 'message' => 'Missing ad_group_id, keyword_text, or customer', 'recommendation' => $rec];
        }

        try {
            $resourceId = (new MicrosoftAdGroupService($customer))->addKeyword($adGroupId, $keyword, $matchType);
            $applied    = $resourceId !== null;

            return [
                'applied'        => $applied,
                'message'        => $applied ? "Keyword '{$keyword}' ({$matchType}) added to Microsoft ad group" : 'Failed to add keyword',
                'recommendation' => $rec,
            ];
        } catch (\Exception $e) {
            return ['applied' => false, 'message' => 'Error: ' . substr($e->getMessage(), 0, 200), 'recommendation' => $rec];
        }
    }

    private function applyFacebookAudience(Campaign $campaign, $customer, array $rec): array
    {
        $accountId = $customer->facebook_ads_account_id;
        if (!$accountId) {
            return ['applied' => false, 'message' => 'Customer has no Facebook Ads account', 'recommendation' => $rec];
        }

        $daysSinceCreation = $campaign->created_at?->diffInDays(now()) ?? 0;
        if ($daysSinceCreation < 14) {
            return ['applied' => false, 'message' => "Campaign only {$daysSinceCreation} days old — audience creation requires 14+ days", 'recommendation' => $rec];
        }

        $subType     = $rec['sub_type'] ?? 'lookalike';
        $audienceName = $rec['audience_name'] ?? $rec['description'] ?? 'Spectra Auto-Audience';
        $service     = new FacebookCustomAudienceService($customer);

        try {
            if ($subType === 'lookalike') {
                $sourceId = $rec['source_audience_id'] ?? null;
                if (!$sourceId) {
                    return ['applied' => false, 'message' => 'Lookalike requires a source_audience_id', 'recommendation' => $rec];
                }
                $result = $service->createLookalikeAudience(
                    $accountId,
                    $sourceId,
                    $audienceName,
                    $rec['country_code'] ?? 'US',
                    (float) ($rec['ratio'] ?? 0.01)
                );
            } else {
                // Website custom audience fallback
                $result = $service->createWebsiteAudience(
                    $accountId,
                    $audienceName,
                    $rec['retention_days'] ?? 30,
                    $rec['pixel_rule'] ?? []
                );
            }

            if (!$result || empty($result['id'])) {
                return ['applied' => false, 'message' => "Failed to create Facebook {$subType} audience", 'recommendation' => $rec];
            }

            Audience::create([
                'customer_id' => $customer->id,
                'campaign_id' => $campaign->id,
                'name'        => $audienceName,
                'platform'    => 'facebook',
                'type'        => $subType,
                'platform_resource_name' => $result['id'],
                'status'      => 'active',
                'source_data' => ['recommendation' => $rec, 'created_by' => 'optimization_agent'],
            ]);

            AgentActivity::record(
                'optimization', 'audience_created',
                "Created Facebook {$subType} audience '{$audienceName}'",
                $customer->id, $campaign->id,
                ['audience_id' => $result['id'], 'sub_type' => $subType]
            );

            return ['applied' => true, 'message' => "Facebook {$subType} audience '{$audienceName}' created", 'recommendation' => $rec];
        } catch (\Exception $e) {
            return ['applied' => false, 'message' => 'Error: ' . substr($e->getMessage(), 0, 200), 'recommendation' => $rec];
        }
    }

    private function applyAudience(Campaign $campaign, array $rec): array
    {
        $customer = $campaign->customer;

        if (!$customer) {
            return ['applied' => false, 'message' => 'Missing customer', 'recommendation' => $rec];
        }

        // Route Facebook audience actions
        if ($campaign->facebook_ads_campaign_id && !$campaign->google_ads_campaign_id) {
            return $this->applyFacebookAudience($campaign, $customer, $rec);
        }

        if (!$campaign->google_ads_campaign_id) {
            return ['applied' => false, 'message' => 'Audience actions not yet supported for this platform', 'recommendation' => $rec];
        }

        $daysSinceCreation = $campaign->created_at?->diffInDays(now()) ?? 0;
        if ($daysSinceCreation < 30) {
            return ['applied' => false, 'message' => "Campaign only {$daysSinceCreation} days old — audience requires 30+ days of data", 'recommendation' => $rec];
        }

        $customerId = $customer->cleanGoogleCustomerId();
        $subType    = $rec['sub_type'] ?? 'remarketing';
        $listName   = $rec['audience_name'] ?? $rec['description'] ?? 'Auto-created audience';

        try {
            $resourceName = match ($subType) {
                'customer_match' => (new CreateUserList($customer))($customerId, $listName, $rec['description'] ?? ''),
                'remarketing'    => (new CreateRemarketingAudience($customer))($customerId, $listName, $rec['url_contains'] ?? '/', (int) ($rec['membership_days'] ?? 90)),
                default          => null,
            };

            if (!$resourceName) {
                return ['applied' => false, 'message' => "Failed to create {$subType} audience", 'recommendation' => $rec];
            }

            Audience::create([
                'customer_id'            => $customer->id,
                'campaign_id'            => $campaign->id,
                'name'                   => $listName,
                'platform'               => 'google',
                'type'                   => $subType,
                'platform_resource_name' => $resourceName,
                'status'                 => 'active',
                'source_data'            => ['recommendation' => $rec, 'created_by' => 'optimization_agent'],
            ]);

            return ['applied' => true, 'message' => "{$subType} audience '{$listName}' created", 'recommendation' => $rec];
        } catch (\Exception $e) {
            return ['applied' => false, 'message' => 'Error: ' . substr($e->getMessage(), 0, 200), 'recommendation' => $rec];
        }
    }
}
