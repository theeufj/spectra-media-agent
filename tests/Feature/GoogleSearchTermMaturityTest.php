<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\CommonServices\GetSearchTermsReport;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsClient;
use Google\Ads\GoogleAds\V26\Common\Metrics;
use Google\Ads\GoogleAds\V26\Resources\AdGroup;
use Google\Ads\GoogleAds\V26\Resources\Campaign;
use Google\Ads\GoogleAds\V26\Resources\Customer as GoogleCustomer;
use Google\Ads\GoogleAds\V26\Resources\SearchTermView;
use Google\Ads\GoogleAds\V26\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V26\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsResponse;
use Google\ApiCore\Testing\MockStatus;
use Google\ApiCore\Testing\MockTransport;
use Google\Auth\Credentials\InsecureCredentials;
use Google\Rpc\Code;
use Tests\TestCase;

class GoogleSearchTermMaturityTest extends TestCase
{
    private MockTransport $transport;

    private GetSearchTermsReport $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-26 00:30:00', 'UTC'));
        config(['optimization.search_terms.conversion_lag_days' => 2]);
        $this->transport = new MockTransport;
        $search = new GoogleAdsServiceClient(['transport' => $this->transport,
            'credentials' => new InsecureCredentials, 'disableRetries' => true]);
        $client = $this->createMock(GoogleAdsClient::class);
        $client->method('getGoogleAdsServiceClient')->willReturn($search);
        $this->source = (new \ReflectionClass(GetSearchTermsReport::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'customer'))->setValue($this->source,
            new Customer(['google_ads_customer_id' => '1234567890']));
        (new \ReflectionProperty(BaseGoogleAdsService::class, 'client'))->setValue($this->source, $client);
    }

    private function response(array $rows, string $page = ''): void
    {
        $this->transport->addResponse(new SearchGoogleAdsResponse(['results' => $rows,
            'next_page_token' => $page]), new MockStatus(Code::OK));
    }

    private function row(): GoogleAdsRow
    {
        return new GoogleAdsRow(['search_term_view' => new SearchTermView(['search_term' => 'google setup jobs']),
            'metrics' => new Metrics(['impressions' => 20, 'clicks' => 2, 'cost_micros' => 30_000_001,
                'conversions' => 0, 'all_conversions' => 1]),
            'campaign' => new Campaign(['resource_name' => 'customers/1234567890/campaigns/61']),
            'ad_group' => new AdGroup(['resource_name' => 'customers/1234567890/adGroups/7'])]);
    }

    public function test_matured_report_uses_complete_days_in_google_account_timezone_and_preserves_all_conversion_evidence(): void
    {
        $this->response([new GoogleAdsRow(['customer' => new GoogleCustomer(['time_zone' => 'America/Los_Angeles'])])]);
        $this->response([$this->row()], 'second-page');
        $this->response([$this->row()]);

        $terms = ($this->source)('1234567890', 'customers/1234567890/campaigns/61', 'MATURED_LAST_30_DAYS');

        $this->assertCount(2, $terms);
        $this->assertSame('2026-10-22', $terms[0]['matured_through']);
        $this->assertSame('2026-09-25', $terms[0]['reported_from']);
        $this->assertSame('America/Los_Angeles', $terms[0]['account_timezone']);
        $this->assertTrue($terms[0]['matured']);
        $this->assertSame(30_000_001, $terms[0]['cost_micros']);
        $this->assertEquals(1, $terms[0]['all_conversions']);
        $calls = $this->transport->popReceivedCalls();
        $this->assertCount(3, $calls);
        $query = $calls[1]->getRequestObject()->getQuery();
        $this->assertStringContainsString("segments.date BETWEEN '2026-09-25' AND '2026-10-22'", $query);
        $this->assertStringContainsString('metrics.all_conversions', $query);
        $this->assertSame('second-page', $calls[2]->getRequestObject()->getPageToken());
    }

    public function test_recent_conversion_protection_includes_today_and_reuses_verified_account_timezone(): void
    {
        $this->response([new GoogleAdsRow(['customer' => new GoogleCustomer(['time_zone' => 'Australia/Sydney'])])]);
        $this->response([$this->row()]);
        $this->response([$this->row()]);

        $recent = ($this->source)('1234567890', 'customers/1234567890/campaigns/61', 'LAST_30_DAYS_INCLUDING_TODAY');
        ($this->source)('1234567890', 'customers/1234567890/campaigns/61', 'MATURED_LAST_30_DAYS');

        $this->assertFalse($recent[0]['matured']);
        $this->assertSame('2026-10-26', $recent[0]['reported_through']);
        $calls = $this->transport->popReceivedCalls();
        $this->assertCount(3, $calls, 'The same source must not refetch account context for its second report.');
        $this->assertStringContainsString("BETWEEN '2026-09-26' AND '2026-10-26'", $calls[1]->getRequestObject()->getQuery());
        $this->assertStringContainsString("BETWEEN '2026-09-26' AND '2026-10-23'", $calls[2]->getRequestObject()->getQuery());
    }

    public function test_unknown_account_timezone_cannot_produce_matured_cost_evidence(): void
    {
        $this->response([new GoogleAdsRow(['customer' => new GoogleCustomer(['time_zone' => 'invalid/timezone'])])]);

        $this->expectException(\RuntimeException::class);
        try {
            ($this->source)('1234567890', 'customers/1234567890/campaigns/61', 'MATURED_LAST_30_DAYS');
        } finally {
            $this->assertCount(1, $this->transport->popReceivedCalls());
        }
    }

    public function test_untrusted_date_clause_is_refused_before_transport(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            ($this->source)('1234567890', 'customers/1234567890/campaigns/61', 'LAST_30_DAYS OR campaign.status = ENABLED');
        } finally {
            $this->assertCount(0, $this->transport->popReceivedCalls());
        }
    }

    public function test_cross_account_report_is_refused_before_transport(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            ($this->source)('9999999999', 'customers/9999999999/campaigns/61', 'MATURED_LAST_30_DAYS');
        } finally {
            $this->assertCount(0, $this->transport->popReceivedCalls());
        }
    }
}
