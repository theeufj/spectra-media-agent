<?php

namespace Tests\Feature;

use App\Services\Deployment\GoogleSearchConfigurationCheck;
use Tests\TestCase;

class GoogleSearchConfigurationCheckTest extends TestCase
{
    private function fixture(): array
    {
        $ad = ['headlines' => ['Google Ads Management'], 'descriptions' => ['Real offer'], 'final_urls' => ['https://example.com']];
        $expected = ['keywords' => [['text' => 'google ads management', 'match_type' => 'EXACT']], 'ads' => [$ad],
            'budget_micros' => 37500000, 'bidding' => 'MAXIMIZE_CONVERSIONS', 'locations' => [2036], 'location_mode' => 'PRESENCE',
            'networks' => ['targetGoogleSearch' => true, 'targetSearchNetwork' => false, 'targetContentNetwork' => false],
            'conversion_category' => 'PURCHASE', 'asset_resources' => ['customers/123/assets/4'], 'verified_offer_assets' => [],
            'sitelink_urls' => ['https://example.com/pricing']];
        $actual = ['campaign' => ['campaign' => ['advertisingChannelType' => 'SEARCH', 'biddingStrategyType' => 'MAXIMIZE_CONVERSIONS',
            'networkSettings' => ['targetGoogleSearch' => true], 'geoTargetTypeSetting' => ['positiveGeoTargetType' => 'PRESENCE']],
            'campaignBudget' => ['amountMicros' => '37500000']],
            'keywords' => [['adGroupCriterion' => ['keyword' => ['text' => 'google ads management', 'matchType' => 'EXACT']]]],
            'criteria' => [['campaignCriterion' => ['location' => ['geoTargetConstant' => 'geoTargetConstants/2036']]]],
            'ads' => [['adGroupAd' => ['ad' => ['responsiveSearchAd' => ['headlines' => [['text' => $ad['headlines'][0]]],
                'descriptions' => [['text' => $ad['descriptions'][0]]]], 'finalUrls' => $ad['final_urls']]]]],
            'assets' => [['campaignAsset' => ['asset' => 'customers/123/assets/4', 'fieldType' => 'SITELINK'], 'asset' => ['finalUrls' => ['https://example.com/pricing']]]],
            'goals' => [['campaignConversionGoal' => ['category' => 'PURCHASE', 'biddable' => true]]],
            'conversion_actions' => [['conversionAction' => ['category' => 'PURCHASE', 'primaryForGoal' => true]]]];

        return [$expected, $actual];
    }

    public function test_matching_campaign_passes_even_when_protobuf_omits_false_flags(): void
    {
        $this->assertSame([], app(GoogleSearchConfigurationCheck::class)->compare(...$this->fixture()));
    }

    public function test_retrieving_a_campaign_does_not_prove_its_configuration(): void
    {
        [$expected, $actual] = $this->fixture();
        $actual['keywords'][0]['adGroupCriterion']['keyword'] = ['text' => 'ERP consulting', 'matchType' => 'BROAD'];
        $actual['campaign']['campaignBudget']['amountMicros'] = '499000000';
        $actual['campaign']['campaign']['networkSettings']['targetContentNetwork'] = true;
        $actual['campaign']['campaign']['geoTargetTypeSetting']['positiveGeoTargetType'] = 'PRESENCE_OR_INTEREST';
        $actual['ads'][0]['adGroupAd']['ad']['finalUrls'] = ['https://wrong.example'];
        $actual['assets'][0]['asset']['finalUrls'] = ['https://example.com'];
        $actual['assets'][] = ['campaignAsset' => ['asset' => 'customers/123/assets/99', 'fieldType' => 'PROMOTION']];
        $actual['conversion_actions'] = [];
        $issues = implode(' ', app(GoogleSearchConfigurationCheck::class)->compare($expected, $actual));
        foreach (['keywords', 'budget', 'network', 'Location', 'landing-page', 'sitelink', 'offer evidence', 'conversion'] as $issue) {
            $this->assertStringContainsString($issue, $issues);
        }
    }

    public function test_absent_baseline_cannot_be_reported_as_verified(): void
    {
        [, $actual] = $this->fixture();
        $this->assertNotEmpty(app(GoogleSearchConfigurationCheck::class)->compare([], $actual));
    }

    public function test_verified_asset_id_does_not_hide_wrong_price_currency_or_discount(): void
    {
        [$expected, $actual] = $this->fixture();
        $price = 'customers/123/assets/5';
        $promo = 'customers/123/assets/6';
        $expected['verified_offer_assets'] = [$price, $promo];
        $expected['verified_offer_details'] = [
            $price => ['offerings' => [['header' => 'Product', 'price_micros' => 149000000, 'currency_code' => 'USD', 'final_url' => 'https://example.com/product']]],
            $promo => ['offer' => ['promotion_target' => 'Product', 'money_amount_off' => ['amount_micros' => 20000000, 'currency_code' => 'USD'], 'final_url' => 'https://example.com/product']],
        ];
        $actual['assets'][] = ['campaignAsset' => ['asset' => $price, 'fieldType' => 'PRICE'], 'asset' => ['priceAsset' => ['priceOfferings' => [
            ['header' => 'Product', 'price' => ['amountMicros' => '149000000', 'currencyCode' => 'USD'], 'finalUrl' => 'https://example.com/product'],
        ]]]];
        $actual['assets'][] = ['campaignAsset' => ['asset' => $promo, 'fieldType' => 'PROMOTION'], 'asset' => ['finalUrls' => ['https://example.com/product'],
            'promotionAsset' => ['promotionTarget' => 'Product', 'moneyAmountOff' => ['amountMicros' => '20000000', 'currencyCode' => 'USD']]]];
        $check = app(GoogleSearchConfigurationCheck::class);
        $this->assertSame([], $check->compare($expected, $actual));
        $actual['assets'][1]['asset']['priceAsset']['priceOfferings'][0]['price']['currencyCode'] = 'AUD';
        $actual['assets'][2]['asset']['promotionAsset']['percentOff'] = 200000;
        $issues = implode(' ', $check->compare($expected, $actual));
        $this->assertStringContainsString('price, currency', $issues);
        $this->assertStringContainsString('promotion differs', $issues);
    }

    public function test_additional_primary_bidding_goal_is_reported(): void
    {
        [$expected, $actual] = $this->fixture();
        $actual['goals'][] = ['campaignConversionGoal' => ['category' => 'SIGNUP', 'biddable' => true]];
        $actual['conversion_actions'][] = ['conversionAction' => ['category' => 'SIGNUP', 'primaryForGoal' => true]];
        $this->assertContains('An additional conversion category is being used for bidding.', app(GoogleSearchConfigurationCheck::class)->compare($expected, $actual));
    }
}
