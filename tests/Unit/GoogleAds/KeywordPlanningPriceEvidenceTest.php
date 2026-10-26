<?php

namespace Tests\Unit\GoogleAds;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordHistoricalMetrics;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordIdeas;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Common\KeywordPlanHistoricalMetrics;
use Google\Ads\GoogleAds\V26\Services\Client\KeywordPlanIdeaServiceClient;
use Google\Ads\GoogleAds\V26\Services\GenerateKeywordHistoricalMetricsResponse;
use Google\Ads\GoogleAds\V26\Services\GenerateKeywordHistoricalMetricsResult;
use Google\Ads\GoogleAds\V26\Services\GenerateKeywordIdeaResponse;
use Google\Ads\GoogleAds\V26\Services\GenerateKeywordIdeaResult;
use Google\ApiCore\Testing\MockStatus;
use Google\ApiCore\Testing\MockTransport;
use Google\Auth\Credentials\InsecureCredentials;
use Google\Rpc\Code;
use Tests\TestCase;

class KeywordPlanningPriceEvidenceTest extends TestCase
{
    private MockTransport $transport;

    private GoogleAdsClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new MockTransport;
        $provider = new KeywordPlanIdeaServiceClient(['transport' => $this->transport,
            'credentials' => new InsecureCredentials, 'disableRetries' => true]);
        $this->client = $this->createMock(GoogleAdsClient::class);
        $this->client->method('getKeywordPlanIdeaServiceClient')->willReturn($provider);
    }

    private function bind(BaseGoogleAdsService $source): void
    {
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($source,
            new Customer(['google_ads_customer_id' => '1234567890']));
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($source, $this->client);
    }

    public function test_ideas_request_actual_average_cost_and_preserve_absent_prices_as_unknown(): void
    {
        $this->transport->addResponse(new GenerateKeywordIdeaResponse(['results' => [
            new GenerateKeywordIdeaResult(['text' => 'measured setup', 'keyword_idea_metrics' => new KeywordPlanHistoricalMetrics([
                'avg_monthly_searches' => 500, 'average_cpc_micros' => 2_500_000, 'low_top_of_page_bid_micros' => 8_000_000])]),
            new GenerateKeywordIdeaResult(['text' => 'unknown setup', 'keyword_idea_metrics' => new KeywordPlanHistoricalMetrics]),
        ]]), new MockStatus(Code::OK));
        $source = (new \ReflectionClass(GenerateKeywordIdeas::class))->newInstanceWithoutConstructor();
        $this->bind($source);
        $rows = $source('1234567890', ['measured setup'], null, null, ['geoTargetConstants/2840'], 2);

        $this->assertSame(2_500_000, $rows[0]['average_cpc_micros']);
        $this->assertNull($rows[1]['average_cpc_micros']);
        $this->assertNull($rows[1]['low_top_of_page_bid_micros']);
        $this->assertNull($rows[1]['avg_monthly_searches']);
        $request = $this->transport->popReceivedCalls()[0]->getRequestObject();
        $this->assertTrue($request->getHistoricalMetricsOptions()->getIncludeAverageCpc());
        $this->assertSame(['geoTargetConstants/2840'], iterator_to_array($request->getGeoTargetConstants()));
    }

    public function test_historical_price_proof_carries_average_cost_and_canonical_close_variants(): void
    {
        $this->transport->addResponse(new GenerateKeywordHistoricalMetricsResponse(['results' => [
            new GenerateKeywordHistoricalMetricsResult(['text' => 'ppc setup', 'close_variants' => ['pay per click setup'],
                'keyword_metrics' => new KeywordPlanHistoricalMetrics(['avg_monthly_searches' => 300, 'average_cpc_micros' => 1_800_000])]),
        ]]), new MockStatus(Code::OK));
        $source = (new \ReflectionClass(GenerateKeywordHistoricalMetrics::class))->newInstanceWithoutConstructor();
        $this->bind($source);
        $result = $source('1234567890', ['ppc setup', 'pay per click setup'], ['geoTargetConstants/2840']);

        $this->assertTrue($result['success']);
        $this->assertSame(1_800_000, $result['keywords']['ppc setup']['average_cpc_micros']);
        $this->assertSame($result['keywords']['ppc setup'], $result['keywords']['pay per click setup']);
        $this->assertNull($result['keywords']['ppc setup']['low_top_of_page_bid_micros']);
        $this->assertTrue($this->transport->popReceivedCalls()[0]->getRequestObject()->getHistoricalMetricsOptions()->getIncludeAverageCpc());
    }
}
