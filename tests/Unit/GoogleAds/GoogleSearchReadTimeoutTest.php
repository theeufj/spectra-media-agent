<?php

namespace Tests\Unit\GoogleAds;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsRequest;
use Google\ApiCore\PagedListResponse;
use Google\ApiCore\RetrySettings;
use Tests\TestCase;

/** SDK boundary only: no credentials, database writes or Google requests. */
class GoogleSearchReadTimeoutTest extends TestCase
{
    public function test_read_sends_the_customer_query_and_a_finite_timeout_without_implicit_sdk_retries(): void
    {
        $query = 'SELECT campaign.id, metrics.impressions FROM campaign WHERE campaign.id = 61';
        $response = $this->createMock(PagedListResponse::class);
        $search = $this->createMock(GoogleAdsServiceClient::class);
        $search->expects($this->once())->method('search')->willReturnCallback(function (SearchGoogleAdsRequest $request, array $options) use ($query, $response) {
            $this->assertSame('3598653839', $request->getCustomerId());
            $this->assertSame($query, $request->getQuery());
            $this->assertSame(['retrySettings' => ['retriesEnabled' => false, 'noRetriesRpcTimeoutMillis' => 30_000]], $options);
            // GAX accepts an override array merged into the service settings.
            $settings = RetrySettings::constructDefault()->with($options['retrySettings']);
            $this->assertFalse($settings->retriesEnabled());
            $this->assertSame(30_000, $settings->getNoRetriesRpcTimeoutMillis());

            return $response;
        });
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn($search);

        $this->assertSame($response, (new GoogleSearchReadProbe($client))->read('3598653839', $query));
    }

    public function test_a_failed_read_propagates_instead_of_becoming_a_zero_activity_response_or_an_implicit_retry(): void
    {
        $search = $this->createMock(GoogleAdsServiceClient::class);
        $search->expects($this->once())->method('search')->willThrowException(new \RuntimeException('Google read deadline exceeded'));
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn($search);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google read deadline exceeded');

        (new GoogleSearchReadProbe($client))->read('3598653839', 'SELECT campaign.id FROM campaign');
    }
}

class GoogleSearchReadProbe extends BaseGoogleAdsService
{
    public function __construct(GoogleAdsClient $client)
    {
        $this->client = $client;
    }

    public function read(string $customerId, string $query): PagedListResponse
    {
        return $this->searchQuery($customerId, $query);
    }
}
