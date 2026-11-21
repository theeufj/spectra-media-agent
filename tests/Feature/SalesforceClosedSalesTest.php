<?php

namespace Tests\Feature;

use App\Jobs\SyncCrmConversions;
use App\Jobs\UploadOfflineConversions;
use App\Models\CrmIntegration;
use App\Models\Customer;
use App\Models\OfflineConversion;
use App\Services\Crm\SalesforceConnector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SalesforceClosedSalesTest extends TestCase
{
    use DatabaseTransactions;

    private const HOST = 'https://crm.example.test';

    private const API = self::HOST.'/services/data/v59.0';

    private function credentials(): array
    {
        return ['instance_url' => self::HOST, 'access_token' => 'test-only', 'consent_field' => 'Ads_Consent__c', 'default_currency' => 'AUD'];
    }

    private function opportunity(string $id): array
    {
        return ['Id' => $id, 'IsWon' => true, 'Amount' => '300.50', 'CloseDate' => '2026-11-20'];
    }

    public function test_won_stages_paginate_using_only_available_fields_and_utc_watermark(): void
    {
        $this->freezeSecond();
        $next = '/services/data/v59.0/query/01gABC-2000';
        $first = $this->opportunity('first') + ['FBCLID__c' => 'test-click', 'Ads_Consent__c' => true, 'LastStageChangeDate' => '2026-11-20T11:15:00.000Z'];
        $second = $this->opportunity('second') + ['Ads_Consent__c' => false];
        $lost = array_replace($first, ['Id' => 'lost', 'IsWon' => false]);
        Http::fake(function (Request $request) use ($first, $second, $lost, $next) {
            if ($request->url() === self::API.'/sobjects/Opportunity/describe') {
                return Http::response(['fields' => array_map(fn ($name) => ['name' => $name], ['FBCLID__c', 'Ads_Consent__c', 'LastStageChangeDate'])]);
            }
            if ($request->url() === self::HOST.$next) {
                return Http::response(['records' => [$lost, $second], 'done' => true]);
            }
            $soql = $request['q'];
            $this->assertStringContainsString('WHERE IsWon = TRUE AND LastModifiedDate >= 2026-11-20T01:13:14Z', $soql);
            $this->assertStringContainsString('ORDER BY LastModifiedDate, Id', $soql);
            $this->assertStringNotContainsString('LIMIT', $soql);
            $this->assertStringNotContainsString('GCLID__c', $soql);
            $this->assertStringNotContainsString('CurrencyIsoCode', $soql);

            return Http::response(['records' => [$first], 'done' => false, 'nextRecordsUrl' => $next]);
        });
        $leads = (new SalesforceConnector($this->credentials()))->fetchClosedLeads(new \DateTimeImmutable('2026-11-20 12:13:14+11:00'));
        $this->assertSame(['first', 'second'], array_column($leads, 'crm_lead_id'));
        $this->assertSame('AUD', $leads[0]['currency_code']);
        $this->assertTrue($leads[0]['ad_measurement_consent']);
        $this->assertTrue($leads[0]['precise_event_time']);
        $this->assertFalse($leads[1]['ad_measurement_consent']);
        $this->assertFalse($leads[1]['precise_event_time']);
        Http::assertSentCount(3);
    }

    public function test_a_continuation_must_stay_on_the_authenticated_salesforce_instance(): void
    {
        Http::fake([
            self::API.'/sobjects/Opportunity/describe' => Http::response(['fields' => []]),
            self::API.'/query*' => Http::response(['records' => [], 'done' => false, 'nextRecordsUrl' => 'https://other.example/data']),
        ]);
        try {
            (new SalesforceConnector($this->credentials()))->fetchClosedLeads(now());
            $this->fail('A foreign continuation cannot receive the Salesforce credential.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Salesforce did not return a valid next opportunity page.', $e->getMessage());
        }
        Http::assertSentCount(2);
    }

    public function test_unbound_closed_sales_deduplicate_as_first_party_only_even_with_consent_and_optional_clicks(): void
    {
        $customer = Customer::factory()->create();
        $integration = CrmIntegration::create(['customer_id' => $customer->id, 'provider' => 'salesforce', 'status' => 'connected', 'credentials' => $this->credentials()]);
        $record = $this->opportunity('won-id') + ['GCLID__c' => 'unbound-click', 'CurrencyIsoCode' => 'USD', 'Ads_Consent__c' => true, 'LastStageChangeDate' => '2026-11-20T01:13:14Z'];
        Http::fake([
            self::API.'/sobjects/Opportunity/describe' => Http::response(['fields' => array_map(fn ($name) => ['name' => $name], array_keys($record))]),
            self::API.'/query*' => Http::response(['records' => [$record], 'done' => true]),
        ]);
        $sync = new SyncCrmConversions($integration->id);
        $sync->handle();
        $sync->handle();
        $row = OfflineConversion::where('crm_integration_id', $integration->id)->sole();
        $this->assertSame('first_party_only', $row->upload_status);
        $this->assertNull($row->gclid);
        $this->assertNull($row->fbclid);
        $this->assertSame('USD', $row->currency_code);
        $this->assertSame('300.50', $row->conversion_value);
        $this->assertSame(1, $integration->fresh()->total_leads_synced);
        Queue::assertNotPushed(UploadOfflineConversions::class);
    }
}
