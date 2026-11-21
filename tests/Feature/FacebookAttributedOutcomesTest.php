<?php

namespace Tests\Feature;

use App\Jobs\FetchFacebookAdsPerformanceData;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\Strategy;
use App\Services\Agents\CreativeTreatmentMetrics;
use App\Services\Campaigns\CampaignResourceInventory;
use App\Services\Conversions\CampaignOutcomeEvidence;
use App\Services\FacebookAds\AdAccountService;
use App\Services\FacebookAds\FacebookAttributionEvidence;
use App\Services\FacebookAds\FacebookCampaignMetrics;
use App\Services\FacebookAds\InsightService;
use App\Services\GoogleAds\RecommendationGenerationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FacebookAttributedOutcomesTest extends TestCase
{
    use DatabaseTransactions;

    private function strategy(string $goal = 'signup'): Strategy
    {
        $customer = Customer::factory()->create(['website' => 'https://example.org', 'facebook_ads_account_id' => 'act_131', 'facebook_pixel_id' => '222']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Facebook', 'conversion_goals' => ['primary_goal' => $goal]]);
        $strategy->forceFill(['facebook_campaign_id' => '501', 'facebook_adset_id' => '601', 'facebook_ad_id' => '701',
            'execution_result' => ['platform_ids' => ['image_ad_1' => '702']]])->save();

        return $strategy->fresh();
    }

    private function sign(Strategy $strategy): void
    {
        $binding = app(CampaignOutcomeEvidence::class)->binding($strategy);
        DB::table('campaign_outcome_events')->insert(['strategy_id' => $strategy->id, 'binding' => $binding['fingerprint'], 'goal' => $binding['goal'],
            'source_key' => hash('sha256', 'verified'), 'source' => 'signed_website', 'mode' => 'test', 'delivery_status' => 'verified',
            'provider_receipt' => 'meta-ack', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function provider(array &$adset, array &$days, array &$treatments)
    {
        $account = $this->createMock(AdAccountService::class);
        $account->method('getAdAccount')->with('131')->willReturn(['id' => 'act_131', 'timezone_name' => 'Pacific/Auckland', 'currency' => 'NZD']);
        $this->app->bind(AdAccountService::class, fn () => $account);
        $insights = $this->createMock(InsightService::class);
        $insights->method('attributionAdSet')->with('601')->willReturnCallback(function () use (&$adset) {
            return $adset;
        });
        $insights->method('getAdSetInsights')->willReturnCallback(function () use (&$days) {
            return $days;
        });
        $insights->method('treatmentInsights')->willReturnCallback(function () use (&$treatments) {
            return $treatments;
        });
        $this->app->bind(InsightService::class, fn () => $insights);

        return $insights;
    }

    private function adset(string $event): array
    {
        return ['id' => '601', 'account_id' => '131', 'campaign_id' => '501', 'updated_time' => '2026-10-01T01:00:00+0000',
            'promoted_object' => ['pixel_id' => '222', 'custom_event_type' => $event]];
    }

    private function row(string $day, string $action): array
    {
        return ['account_id' => '131', 'account_currency' => 'NZD', 'campaign_id' => '501', 'adset_id' => '601', 'date_start' => $day, 'date_stop' => $day,
            'impressions' => '99', 'inline_link_clicks' => '4', 'spend' => '3.21', 'actions' => [
                ['action_type' => 'omni_purchase', 'value' => '987'], ['action_type' => 'lead', 'value' => '987'], ['action_type' => $action, 'value' => '2'],
            ], 'action_values' => [['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '75.13']]];
    }

    public static function events(): array
    {
        return [['signup', 'COMPLETE_REGISTRATION', 'complete_registration'], ['lead', 'LEAD', 'lead'], ['purchase', 'PURCHASE', 'purchase']];
    }

    #[DataProvider('events')]
    public function test_daily_ingestion_proves_only_signed_current_goal_and_preserves_actual_currency(string $goal, string $event, string $action): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-01 18:00:00 UTC')); // Nov 2 in the ad account.
        $strategy = $this->strategy($goal);
        $days = $treatments = [];
        $adset = $this->adset($event);
        $insights = $this->provider($adset, $days, $treatments);
        $entry = CampaignResourceInventory::entries($strategy->campaign)[0];
        $evidence = app(FacebookAttributionEvidence::class);
        $this->assertNull($evidence->observe($strategy, $entry, $insights)); // No signed receipt.
        $this->sign($strategy);
        $this->assertSame('2026-11-03', $evidence->observe($strategy, $entry, $insights)?->first_full_day);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-04 00:00:00 UTC'));
        $days = [$this->row('2026-11-03', 'offsite_conversion.fb_pixel_'.$action)];
        $legacy = $this->row('2026-11-01', 'offsite_conversion.fb_pixel_'.$action);
        $insights->method('getCampaignInsights')->willReturn([$legacy, $days[0]]);
        $this->app->bind(RecommendationGenerationService::class, fn () => $this->createMock(RecommendationGenerationService::class));
        (new FetchFacebookAdsPerformanceData($strategy->campaign))->handle();
        $row = FacebookAdsPerformanceData::where('strategy_id', $strategy->id)->whereDate('date', '2026-11-03')->sole();
        $this->assertSame('131', $row->facebook_account_id);
        $this->assertSame('NZD', $row->currency);
        $this->assertEquals(2, $row->attributed_conversions);
        $this->assertNull(FacebookAdsPerformanceData::where('strategy_id', $strategy->id)->whereDate('date', '2026-11-01')->value('attribution_window'));
        $state = app(CampaignOutcomeEvidence::class)->forStrategy($strategy);
        $this->assertSame('reported', $state['provider_attribution']);
        $this->assertEquals($goal === 'purchase' ? '75.130' : null, $state['provider_metrics']['purchase_value']);
        $report = app(\App\Services\Reporting\YesterdayPerformanceSummary::class)->forCustomer($strategy->campaign->customer, '2026-11-03');
        $this->assertEquals(2, $report['facebook']['conversions']);
        $this->assertSame('NZD', $report['combined']['currency']);
        $this->assertSame(0, $state['verified_events']); // A signed test does not become a live first-party purchase.
        $days[0]['actions'] = [['action_type' => 'omni_'.$action, 'value' => '654']];
        $evidence->synchronize($strategy, $entry, $insights);
        $this->assertSame('unverified', app(CampaignOutcomeEvidence::class)->forStrategy($strategy)['provider_attribution']);
        $this->assertEquals(0, $row->fresh()->attributed_conversions);
    }

    public function test_wrong_dataset_adset_account_and_dataset_switched_back_cannot_relabel_prior_conversions(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-10 00:00 UTC'));
        $strategy = $this->strategy();
        $this->sign($strategy);
        $entry = CampaignResourceInventory::entries($strategy->campaign)[0];
        $adset = $this->adset('COMPLETE_REGISTRATION');
        $days = $treatments = [];
        $insights = $this->provider($adset, $days, $treatments);
        $proof = app(FacebookAttributionEvidence::class);
        $original = $proof->observe($strategy, $entry, $insights);
        $this->assertNotNull($original);
        foreach (['account_id' => '132', 'campaign_id' => '502', 'id' => '602'] as $key => $wrong) {
            $adset = array_replace($this->adset('COMPLETE_REGISTRATION'), [$key => $wrong]);
            $this->assertNull($proof->observe($strategy, $entry, $insights));
        }
        $adset = $this->adset('LEAD');
        $this->assertNull($proof->observe($strategy, $entry, $insights));
        $adset = $this->adset('COMPLETE_REGISTRATION');
        $adset['promoted_object']['pixel_id'] = '333';
        $this->assertNull($proof->observe($strategy, $entry, $insights));
        $this->travel(3)->days();
        $adset['promoted_object']['pixel_id'] = '222';
        $adset['updated_time'] = now()->subHour()->toIso8601String();
        $current = $proof->observe($strategy, $entry, $insights);
        $this->assertNotSame($original->window_id, $current?->window_id);
        $this->assertSame(now('Pacific/Auckland')->addDay()->toDateString(), $current?->first_full_day);
    }

    public function test_comparisons_use_only_managed_ads_the_current_set_and_exact_offsite_goal(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-10 00:00 UTC'));
        $strategy = $this->strategy();
        $this->sign($strategy);
        $entry = CampaignResourceInventory::entries($strategy->campaign)[0];
        $adset = $this->adset('COMPLETE_REGISTRATION');
        $days = $treatments = [];
        $insights = $this->provider($adset, $days, $treatments);
        $proof = app(FacebookAttributionEvidence::class);
        $proof->observe($strategy, $entry, $insights);
        $this->travel(3)->days();
        $proof->observe($strategy, $entry, $insights);
        $date = '2026-11-11';
        $row = $this->row($date, 'offsite_conversion.fb_pixel_complete_registration');
        $treatments = [array_merge($row, ['ad_id' => '701']), array_merge($row, ['ad_id' => '703'])];
        $service = app(CreativeTreatmentMetrics::class);
        $this->assertSame([701], array_keys($service->read($strategy, $date, $date)));
        $this->assertSame(2.0, $service->read($strategy, $date, $date)[701]['conversions']);
        $treatments[0]['account_currency'] = 'USD';
        $this->assertSame([], $service->read($strategy, $date, $date));
        $treatments[0]['account_currency'] = 'NZD';
        $adset['promoted_object']['pixel_id'] = '333';
        $this->assertSame([], $service->read($strategy, $date, $date));
        $this->assertSame([], $service->read($strategy, '2026-11-09', $date));
    }

    public function test_customer_digest_keeps_currencies_separate_and_legacy_meta_outcomes_unverified(): void
    {
        $strategy = $this->strategy();
        $day = now()->subDay()->toDateString();
        $data = ['date' => $day, 'campaign_id' => $strategy->campaign_id, 'impressions' => 9, 'clicks' => 2, 'cost' => '7.25',
            'conversions' => 99, 'conversion_value' => 999];
        FacebookAdsPerformanceData::create([...$data, 'strategy_id' => $strategy->id, 'facebook_account_id' => '131', 'facebook_campaign_id' => '501', 'currency' => 'NZD']);
        \App\Models\GoogleAdsPerformanceData::create([...$data, 'google_account_id' => '555', 'google_campaign_id' => '888', 'currency' => 'USD']);
        $summary = app(\App\Services\Reporting\YesterdayPerformanceSummary::class)->forCustomer($strategy->campaign->customer, $day);
        $this->assertNull($summary['facebook']['conversions']);
        $this->assertSame(['NZD', 'USD'], array_keys($summary['combined']['money_by_currency']));
        $this->assertNull($summary['combined']['spend']);
        $this->assertNull($summary['combined']['roas']);
        $this->assertSame(99.0, $summary['combined']['confirmed_conversions']);
        $email = (new \App\Mail\DailyPerformanceReport(\App\Models\User::factory()->create(), $summary))->render();
        $this->assertStringContainsString('NZD 7.25 + USD 7.25', $email);
        $this->assertStringContainsString('Unverified', $email);
    }

    public function test_budget_comparisons_and_reapproval_require_exact_actions_and_one_currency(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-10 00:00 UTC'));
        $meta = $this->strategy('purchase');
        $customer = $meta->campaign->customer;
        $customer->forceFill(['currency_code' => 'NZD', 'google_ads_customer_id' => '555'])->save();
        $meta->campaign->forceFill(['status' => 'active'])->save();
        $this->sign($meta);
        $entry = CampaignResourceInventory::entries($meta->campaign)[0];
        $adset = $this->adset('PURCHASE');
        $days = $treatments = [];
        $insights = $this->provider($adset, $days, $treatments);
        $proof = app(FacebookAttributionEvidence::class);
        $proof->observe($meta, $entry, $insights);
        $google = Strategy::factory()->create(['campaign_id' => $meta->campaign_id, 'platform' => 'Google', 'daily_budget' => 50,
            'conversion_goals' => ['primary_goal' => 'Purchase', 'action_resource' => 'customers/555/conversionActions/42']]);
        $google->forceFill(['google_ads_campaign_id' => 'customers/555/campaigns/888'])->save();
        $this->sign($google);
        $this->travel(3)->days();
        $date = now('Pacific/Auckland')->subDay()->toDateString();
        $day = ['date' => $date, 'campaign_id' => $meta->campaign_id, 'impressions' => 120, 'clicks' => 20, 'cost' => 100,
            'conversions' => 900, 'conversion_value' => 99999, 'currency' => 'NZD'];
        $gRow = \App\Models\GoogleAdsPerformanceData::create([...$day, 'strategy_id' => $google->id, 'google_account_id' => '555', 'google_campaign_id' => '888']);
        $mRow = FacebookAdsPerformanceData::create([...$day, 'strategy_id' => $meta->id, 'facebook_account_id' => '131', 'facebook_campaign_id' => '501']);
        $days = [$this->row($date, 'offsite_conversion.fb_pixel_purchase')];
        $days[0]['actions'][2]['value'] = '20';
        $proof->synchronize($meta, $entry, $insights);
        $googleApi = $this->createMock(\App\Services\GoogleAds\ConversionTrackingService::class);
        $googleApi->method('budgetActionTotals')->willReturn(['conversions' => 20, 'conversion_value' => 650]);
        $this->app->bind(\App\Services\GoogleAds\ConversionTrackingService::class, fn () => $googleApi);
        $allocator = $this->getMockBuilder(\App\Services\CrossChannelBudgetAllocator::class)->onlyMethods(['getAiReasoning'])->getMock();
        $allocator->method('getAiReasoning')->willReturn(null);
        $first = $allocator->analyze($customer);
        $this->assertEquals(20, $first['snapshot']['facebook_ads']['conversions']);
        $this->assertEquals(650, $first['snapshot']['google_ads']['conversion_value']);
        $fingerprint = $first['recommendations']['approval_fingerprint'];
        $intelligence = new \App\Services\Agents\BudgetIntelligenceAgent;
        $suggestion = $intelligence->checkCrossPlatformReallocation($customer);
        $this->assertSame('NZD', $suggestion['currency'] ?? null);
        $gRow->update(['currency' => 'USD']);
        $this->assertArrayNotHasKey('suggested_splits', $allocator->analyze($customer)['recommendations']);
        $this->assertSame('no_changes', $allocator->rebalance($customer, 'manual', $fingerprint)['status']);
        $this->assertNull($intelligence->checkCrossPlatformReallocation($customer));
        $this->assertNull(\Illuminate\Support\Facades\Cache::get('cross_platform_reallocation:'.$customer->id));
        $gRow->update(['currency' => 'NZD']);
        $mRow->update(['attribution_window' => null]);
        $this->assertSame('no_changes', $allocator->rebalance($customer, 'manual', $fingerprint)['status']);
        $this->assertNull($intelligence->checkCrossPlatformReallocation($customer));
    }

    public function test_failed_daily_proof_is_retryable_and_cannot_leave_a_fresh_proof(): void
    {
        $strategy = $this->strategy();
        $this->sign($strategy);
        $adset = $this->adset('COMPLETE_REGISTRATION');
        $days = $treatments = [];
        $insights = $this->provider($adset, $days, $treatments);
        $entry = CampaignResourceInventory::entries($strategy->campaign)[0];
        $proof = app(FacebookAttributionEvidence::class);
        $proof->observe($strategy, $entry, $insights);
        $this->travel(3)->days();
        $days = [$this->row(now('Pacific/Auckland')->subDay()->toDateString(), 'offsite_conversion.fb_pixel_complete_registration')];
        $days[0]['account_id'] = '999';
        try {
            $proof->synchronize($strategy, $entry, $insights);
            $this->fail('A foreign account report must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('did not match', $e->getMessage());
        }
        $this->assertNull($proof->current($strategy, app(CampaignOutcomeEvidence::class)->binding($strategy)));
        $this->assertNull(FacebookCampaignMetrics::pixelAmount([['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => 'x']], 'purchase'));
    }
}
