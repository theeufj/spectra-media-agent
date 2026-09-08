<?php

namespace Tests\Feature;

use App\Services\MicrosoftAds\AdGroupService;
use Microsoft\MsAds\Rest\Model\CampaignManagementService\AssetLink;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Microsoft responsive search ads used to send `['Text' => $headline]` straight
 * into `Headlines`. `ResponsiveSearchAd::$Headlines` is `AssetLink[]`, and
 * AssetLink has exactly four members — none of them `Text` — so every ad went
 * up with no headlines and no descriptions. AddAds answers 200 with a
 * PartialErrors block rather than a SoapFault, so the agent recorded
 * `'ad_created' => 'yes'` and the strategy was marked deployed.
 *
 * The vendored SDK is the ground truth these assertions read from.
 */
class MicrosoftResponsiveSearchAdPayloadTest extends TestCase
{
    private function buildAd(array $ad): \SoapVar
    {
        // newInstanceWithoutConstructor: the payload builder is pure, and the
        // real constructor would try to authenticate against Microsoft.
        $service = (new ReflectionClass(AdGroupService::class))->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(AdGroupService::class, 'buildResponsiveSearchAd');
        $method->setAccessible(true);

        return $method->invoke($service, $ad);
    }

    private function sampleAd(): \SoapVar
    {
        return $this->buildAd([
            'headlines' => ['Fast Quotes', 'No Call-Out Fee'],
            'descriptions' => ['Book a licensed pro today.'],
            'path1' => 'AI-Ads',
            'path2' => 'Managed',
            'final_url' => 'https://example.test/quote',
        ]);
    }

    public function test_headline_text_lives_on_a_text_asset_not_on_the_asset_link(): void
    {
        $links = $this->sampleAd()->enc_value['Headlines']['AssetLink'];

        $this->assertCount(2, $links);

        foreach ($links as $link) {
            // AssetLink's only members, straight out of the vendored SDK.
            $this->assertSame(
                [],
                array_diff(array_keys($link), array_keys(AssetLink::openAPITypes())),
                'AssetLink was sent a field the SDK does not declare'
            );
            $this->assertArrayNotHasKey('Text', $link);
        }

        $asset = $links[0]['Asset'];

        $this->assertInstanceOf(\SoapVar::class, $asset);
        $this->assertSame('TextAsset', $asset->enc_stype);
        $this->assertSame('Fast Quotes', $asset->enc_value['Text']);
        $this->assertSame('TextAsset', $asset->enc_value['Type']);
    }

    public function test_descriptions_use_the_same_asset_link_shape(): void
    {
        $links = $this->sampleAd()->enc_value['Descriptions']['AssetLink'];

        $this->assertCount(1, $links);
        $this->assertSame('Book a licensed pro today.', $links[0]['Asset']->enc_value['Text']);
    }

    public function test_the_ad_is_typed_as_a_responsive_search_ad_on_the_wire(): void
    {
        $ad = $this->sampleAd();

        // Ad and Asset are abstract in the SOAP schema. Without an explicit
        // concrete type the WSDL-typed SoapClient encodes against the base type
        // and silently drops Headlines, Descriptions, Path1 and Path2.
        $this->assertSame('ResponsiveSearchAd', $ad->enc_stype);
        $this->assertSame('https://bingads.microsoft.com/CampaignManagement/v13', $ad->enc_ns);
        $this->assertSame('ResponsiveSearchAd', $ad->enc_value['Type']);

        // FinalUrls is the one array that was already wrapped in its element
        // name; Headlines and Descriptions now match it.
        $this->assertSame(['https://example.test/quote'], $ad->enc_value['FinalUrls']['string']);
    }

    public function test_an_ad_with_no_copy_produces_empty_asset_link_lists(): void
    {
        $ad = $this->buildAd(['final_url' => 'https://example.test']);

        $this->assertSame([], $ad->enc_value['Headlines']['AssetLink']);
        $this->assertSame([], $ad->enc_value['Descriptions']['AssetLink']);
    }
}
