<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\PerformanceMaxServices\CreatePerformanceMaxCampaign;
use App\Services\GoogleAds\PerformanceMaxServices\PerformanceMaxCopy;
use App\Services\GoogleAds\PerformanceMaxServices\PerformanceMaxLaunchCheck;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Enums\AssetAutomationStatusEnum\AssetAutomationStatus;
use Google\Ads\GoogleAds\V26\Enums\AssetAutomationTypeEnum\AssetAutomationType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignBudgetResult;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignResult;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsRequest;
use Google\Ads\GoogleAds\V26\Services\MutateGoogleAdsResponse;
use Google\Ads\GoogleAds\V26\Services\MutateOperationResponse;
use Tests\TestCase;

class PerformanceMaxLaunchCheckTest extends TestCase
{
    public function test_creator_sends_a_paused_campaign_and_its_budget_with_real_v26_messages(): void
    {
        $google = $this->createMock(GoogleAdsServiceClient::class);
        $google->expects($this->once())->method('mutate')->willReturnCallback(function (MutateGoogleAdsRequest $request): MutateGoogleAdsResponse {
            $budget = $request->getMutateOperations()[0]->getCampaignBudgetOperation()->getCreate();
            $campaign = $request->getMutateOperations()[1]->getCampaignOperation()->getCreate();
            $this->assertSame($budget->getResourceName(), $campaign->getCampaignBudget());
            $this->assertSame(60_000_000, (int) $budget->getAmountMicros());
            $this->assertFalse($request->getPartialFailure());
            $this->assertSame(CampaignStatus::PAUSED, $campaign->getStatus());
            $this->assertSame(AssetAutomationType::FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION, $campaign->getAssetAutomationSettings()[0]->getAssetAutomationType());
            $this->assertSame(AssetAutomationStatus::OPTED_OUT, $campaign->getAssetAutomationSettings()[0]->getAssetAutomationStatus());

            return new MutateGoogleAdsResponse(['mutate_operation_responses' => [
                new MutateOperationResponse(['campaign_budget_result' => new MutateCampaignBudgetResult(['resource_name' => 'customers/1234567890/campaignBudgets/70'])]),
                new MutateOperationResponse(['campaign_result' => new MutateCampaignResult(['resource_name' => 'customers/1234567890/campaigns/71'])]),
            ]]);
        });
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn($google);
        $creator = (new \ReflectionClass(CreatePerformanceMaxCampaign::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($creator, $client);
        $customer = $this->createMock(Customer::class);
        $customer->method('cleanGoogleCustomerId')->willReturn('1234567890');
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($creator, $customer);

        $this->assertSame('customers/1234567890/campaigns/71', $creator('1234567890', [
            'businessName' => 'Managed advertising', 'budget' => '60.00', 'startDate' => '2026-11-17', 'endDate' => '2026-11-22',
        ]));
    }

    public function test_a_cut_off_description_cannot_be_selected_for_performance_max(): void
    {
        $this->assertFalse(PerformanceMaxCopy::hasCompleteDescriptions([
            'Plan your Google Ads campaign.',
            'Use our campaign management services to track ad-c',
        ]));
        $this->assertTrue(PerformanceMaxCopy::hasCompleteDescriptions([
            'Plan your Google Ads campaign.',
            'Track results from your ongoing campaign.',
        ]));
    }

    public function test_paused_stage_requires_the_reviewed_budget_destination_and_bidding(): void
    {
        $stage = ['campaign_resource' => 'customers/123/campaigns/456', 'asset_group' => 'customers/123/assetGroups/789',
            'final_urls' => ['https://example.com/management'], 'budget_micros' => 50_000_000];
        $snapshot = ['campaign' => ['campaign' => ['resourceName' => $stage['campaign_resource'], 'status' => 'PAUSED',
            'advertisingChannelType' => 'PERFORMANCE_MAX', 'biddingStrategyType' => 'MAXIMIZE_CONVERSIONS',
            'assetAutomationSettings' => [
                ['assetAutomationType' => 'FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION', 'assetAutomationStatus' => 'OPTED_OUT'],
                ['assetAutomationType' => 'TEXT_ASSET_AUTOMATION', 'assetAutomationStatus' => 'OPTED_OUT'],
            ]], 'campaignBudget' => ['amountMicros' => '50000000']],
            'groups' => [['assetGroup' => ['resourceName' => $stage['asset_group'], 'status' => 'ENABLED',
                'finalUrls' => $stage['final_urls']]]]];
        $check = new PerformanceMaxLaunchCheck;
        $this->assertTrue($check->matches($stage, $snapshot));
        $this->assertFalse($check->matches([...$stage, 'budget_micros' => 20_000_000], $snapshot));
        $this->assertFalse($check->matches([...$stage, 'final_urls' => ['https://example.com/other']], $snapshot));
        $snapshot['campaign']['campaign']['maximizeConversions']['targetCpaMicros'] = '2500000';
        $this->assertFalse($check->matches($stage, $snapshot));
    }
}
