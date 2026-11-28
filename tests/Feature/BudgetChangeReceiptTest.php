<?php

namespace Tests\Feature;

use App\Contracts\Ads\BudgetMutator;
use App\Contracts\Ads\ReportsBudgetChanges;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Role;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Ads\CustomerRoutedAdsServiceFactory;
use App\Services\Agents\BudgetIntelligenceAgent;
use App\Services\Campaigns\BudgetAllocationService;
use App\Services\Campaigns\CampaignBudgetService;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\Campaigns\GoogleBudgetPacingHistory;
use App\Services\Campaigns\GoogleCampaignSpendSafety;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBudget;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BudgetChangeReceiptTest extends TestCase
{
    use DatabaseTransactions;

    private function campaign(array $attributes = [], array $customerAttributes = []): Campaign
    {
        $customer = Customer::factory()->create($customerAttributes + ['google_ads_customer_id' => '3141592653', 'currency_code' => 'USD']);

        return Campaign::factory()->create($attributes + ['customer_id' => $customer->id, 'google_ads_campaign_id' => '456',
            'daily_budget' => '50.00', 'approved_daily_budget' => '50.00']);
    }

    private function snapshot(Campaign $campaign, int $micros = 50_000_000): array
    {
        return ['campaign_resource' => $campaign->googleAdsResourceName(), 'budget_resource' => 'customers/3141592653/campaignBudgets/789',
            'daily_budget_micros' => $micros, 'currency_code' => 'AUD', 'account_timezone' => 'Australia/Sydney'];
    }

    public function test_fixed_search_budget_resists_reconciliation_and_keeps_its_daily_planning_allocation(): void
    {
        $campaign = $this->campaign(['status' => 'active', 'budget_confirmed_at' => now(), 'spend_guardrails' => ['google_total_budget' => null]]);
        $legacy = Campaign::factory()->create(['customer_id' => $campaign->customer_id, 'status' => 'active', 'daily_budget' => 10]);
        $writer = new ReceiptGoogleBudgetWriter($campaign->customer, []);
        $this->assertFalse($writer->updateWithChanges('3141592653', $campaign->googleAdsResourceName(), 50_000_000)['success']);
        $this->assertSame([], $writer->writes);
        $this->assertArrayHasKey('read_only_reason', app(BudgetIntelligenceAgent::class)->optimize($campaign));
        $this->assertFalse(app(CampaignBudgetService::class)->apply($campaign, 25));
        $this->assertFalse(CampaignSpendGuardrails::permitsBudget($campaign, 25));
        $this->assertTrue(CampaignSpendGuardrails::permitsDailyBudget($campaign, 50_000_000));

        $this->assertTrue(app(BudgetAllocationService::class)($campaign->customer, 70));
        $this->assertEquals(50, $campaign->fresh()->daily_budget);
        $this->assertEquals(20, $legacy->fresh()->daily_budget);
    }

    public function test_daily_budget_trial_cannot_replace_the_fixed_cap(): void
    {
        $campaign = $this->campaign(['spend_guardrails' => ['google_total_budget' => null]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A daily-budget trial cannot replace');
        app(GoogleCampaignSpendSafety::class)->configureBoundedTrial($campaign, 300_000_000, 50_000_000, 2_000_000, 4_000_000, 'Requested trial');
    }

    public function test_admin_cannot_change_dates_or_budget_after_total_budget_approval_even_if_the_campaign_marker_was_lost(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));
        $campaign = $this->campaign(['start_date' => '2026-11-21', 'end_date' => '2026-12-02']);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'google', 'campaign_type' => 'search',
            'bidding_strategy' => ['google_total_budget' => null]]);
        $url = route('admin.campaigns.update', $campaign);
        $this->actingAs($admin)->put($url, ['start_date' => '2026-11-21', 'end_date' => '2026-12-03', 'daily_budget' => 20])
            ->assertSessionHasErrors('total_budget');
        $this->assertSame('2026-12-02', $campaign->fresh()->end_date->toDateString());
        $this->assertEquals(50, $campaign->fresh()->daily_budget);
        $this->put($url, ['name' => 'Renamed safely'])->assertSessionHasNoErrors();
        $this->assertSame('Renamed safely', $campaign->fresh()->name);
    }

    public function test_google_custom_period_or_total_amount_prevents_a_daily_write_even_without_a_local_marker(): void
    {
        $campaign = $this->campaign();
        foreach ([['custom_period' => true], ['total_budget_micros' => 150_000_000]] as $remote) {
            foreach ([false, true] as $automatic) {
                $writer = new ReceiptGoogleBudgetWriter($campaign->customer, [$this->snapshot($campaign, 0) + $remote + ['platform_status' => 'ENABLED']]);
                $this->assertFalse($writer->updateWithChanges('3141592653', $campaign->googleAdsResourceName(), 50_000_000, $automatic)['success']);
                $this->assertSame([], $writer->writes);
            }
        }
    }

    public function test_google_matching_budget_is_confirmed_without_repeating_the_write(): void
    {
        $campaign = $this->campaign();
        $writer = new ReceiptGoogleBudgetWriter($campaign->customer, [$this->snapshot($campaign), $this->snapshot($campaign)]);

        $result = $writer->updateWithChanges('3141592653', $campaign->googleAdsResourceName(), 50_000_000);

        $this->assertSame(['success' => true, 'changes' => []], $result);
        $this->assertSame([], $writer->writes);
    }

    public function test_google_rejects_a_matching_budget_that_changes_before_confirmation(): void
    {
        $campaign = $this->campaign();
        $writer = new ReceiptGoogleBudgetWriter($campaign->customer, [$this->snapshot($campaign), $this->snapshot($campaign, 25_000_000)]);

        $result = $writer->updateWithChanges('3141592653', $campaign->googleAdsResourceName(), 50_000_000);

        $this->assertSame(['success' => false, 'changes' => []], $result);
        $this->assertSame([], $writer->writes);
    }

    public function test_google_receipt_uses_actual_remote_budget_and_currency_when_local_values_are_different(): void
    {
        $campaign = $this->campaign();
        $writer = new ReceiptGoogleBudgetWriter($campaign->customer, [$this->snapshot($campaign, 25_000_000), $this->snapshot($campaign)]);

        $result = $writer->updateWithChanges('3141592653', $campaign->googleAdsResourceName(), 50_000_000);

        $this->assertTrue($result['success']);
        $this->assertSame([['platform' => 'google', 'resource_name' => $campaign->googleAdsResourceName(),
            'old_budget_micros' => 25_000_000, 'new_budget_micros' => 50_000_000, 'currency' => 'AUD']], $result['changes']);
        $this->assertSame('50.00', $campaign->fresh()->daily_budget);
    }

    public function test_automatic_google_reconciliation_leaves_a_remotely_paused_placement_untouched(): void
    {
        $campaign = $this->campaign();
        $writer = new ReceiptGoogleBudgetWriter($campaign->customer, [$this->snapshot($campaign, 25_000_000) + ['platform_status' => 'PAUSED']]);

        $result = $writer->updateWithChanges('3141592653', $campaign->googleAdsResourceName(), 50_000_000, automatic: true);

        $this->assertSame(['success' => true, 'changes' => []], $result);
        $this->assertSame([], $writer->writes);
    }

    public function test_automatic_google_reconciliation_cannot_adjust_a_budget_shared_with_other_campaigns(): void
    {
        $campaign = $this->campaign();
        $writer = new ReceiptGoogleBudgetWriter($campaign->customer, [$this->snapshot($campaign, 25_000_000) + [
            'platform_status' => 'ENABLED', 'explicitly_shared' => true,
        ]]);

        $result = $writer->updateWithChanges('3141592653', $campaign->googleAdsResourceName(), 50_000_000, automatic: true);

        $this->assertSame(['success' => false, 'changes' => []], $result);
        $this->assertSame([], $writer->writes);
    }

    public function test_hourly_budget_respects_a_fresh_pause_even_when_old_serving_status_is_still_eligible(): void
    {
        $campaign = $this->campaign(['status' => 'active', 'primary_status' => 'ELIGIBLE']);
        $stale = $campaign->fresh();
        $campaign->update(['status' => 'paused']);

        $result = app(BudgetIntelligenceAgent::class)->optimize($stale);

        $this->assertSame([], $result['adjustments']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('The campaign is paused or has ended.', $result['read_only_reason']);
        Http::assertNothingSent();
    }

    public function test_automatic_reconciliation_reserves_the_paused_google_share_and_only_reads_an_already_matching_meta_budget(): void
    {
        config(['services.facebook.system_user_token' => 'receipt-test-token']);
        $campaign = $this->campaign(
            ['status' => 'active', 'daily_budget' => '100.00', 'approved_daily_budget' => '100.00', 'facebook_ads_campaign_id' => '789'],
            ['facebook_ads_account_id' => 'act_123']
        );
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads (SEM)', 'daily_budget' => '60.00', 'deployment_status' => 'paused']);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Facebook Ads', 'daily_budget' => '40.00', 'deployment_status' => 'active']);
        $factory = $this->createMock(CustomerRoutedAdsServiceFactory::class);
        $factory->expects($this->never())->method('budgets');
        Http::fake([
            'graph.facebook.com/*/789*' => Http::response(['id' => '789', 'account_id' => '123', 'status' => 'ACTIVE', 'daily_budget' => '4000']),
        ]);

        $result = (new BudgetIntelligenceAgent($factory))->optimize($campaign);

        $this->assertSame([], $result['errors']);
        $this->assertNotContains('budget_updated', array_column($result['adjustments'], 'type'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/789'));
    }

    public function test_an_actual_meta_write_failure_keeps_google_proof_and_retries_the_meta_share(): void
    {
        config(['services.facebook.system_user_token' => 'receipt-test-token']);
        $campaign = $this->campaign(
            ['status' => 'active', 'daily_budget' => '100.00', 'approved_daily_budget' => '100.00', 'facebook_ads_campaign_id' => '789'],
            ['facebook_ads_account_id' => 'act_123']
        );
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'daily_budget' => '60.00']);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Facebook Ads', 'daily_budget' => '40.00']);
        $writer = new class implements ReportsBudgetChanges
        {
            private bool $confirmed = false;

            public function updateDailyBudget(string $customerId, string $campaignResourceName, float $newDailyBudgetMicros): bool
            {
                return $this->updateDailyBudgetWithChanges($customerId, $campaignResourceName, $newDailyBudgetMicros)['success'];
            }

            public function updateDailyBudgetWithChanges(string $customerId, string $campaignResourceName, float $newDailyBudgetMicros): array
            {
                $changes = $this->confirmed ? [] : [['platform' => 'google', 'resource_name' => $campaignResourceName,
                    'old_budget_micros' => 30_000_000, 'new_budget_micros' => (int) $newDailyBudgetMicros, 'currency' => 'AUD']];
                $this->confirmed = true;

                return ['success' => true, 'changes' => $changes];
            }
        };
        $factory = $this->createMock(CustomerRoutedAdsServiceFactory::class);
        $factory->method('budgets')->willReturn($writer);
        $remote = ['id' => '789', 'account_id' => '123', 'status' => 'ACTIVE'];
        Http::fake([
            'graph.facebook.com/*/act_123*' => Http::response(['id' => 'act_123', 'currency' => 'AUD']),
            'graph.facebook.com/*/789*' => Http::sequence()
                ->push($remote + ['daily_budget' => '2000'])->push(['error' => ['type' => 'OAuthException', 'code' => 200]], 400)
                ->push($remote + ['daily_budget' => '2000'])->push(['success' => true])->push($remote + ['daily_budget' => '4000']),
        ]);
        Exceptions::fake();
        $agent = new BudgetIntelligenceAgent($factory);

        $first = $agent->optimize($campaign);
        $this->assertSame(['Budget reconciliation failed for facebook.'], $first['errors']);
        $this->assertSame('google', $first['adjustments'][1]['platform']);
        $this->assertSame(60_000_000, $first['adjustments'][1]['new_budget_micros']);
        Exceptions::assertReported(fn (\RuntimeException $e) => str_contains($e->getMessage(), 'Budget reconciliation failed for facebook.'));

        $retry = $agent->optimize($campaign);
        $this->assertSame([], $retry['errors']);
        $this->assertSame('facebook', $retry['adjustments'][1]['platform']);
        $this->assertSame(40_000_000, $retry['adjustments'][1]['new_budget_micros']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && (int) $request['daily_budget'] === 4000);
    }

    public function test_automatic_meta_reconciliation_rejects_a_campaign_from_another_ad_account(): void
    {
        config(['services.facebook.system_user_token' => 'receipt-test-token']);
        $campaign = $this->campaign(['status' => 'active', 'google_ads_campaign_id' => null, 'facebook_ads_campaign_id' => '789'], ['facebook_ads_account_id' => '123']);
        Http::fake([
            'graph.facebook.com/*/789*' => Http::response(['id' => '789', 'account_id' => '987', 'status' => 'ACTIVE', 'daily_budget' => '2500']),
        ]);

        $result = app(CampaignBudgetService::class)->applyWithChanges($campaign, 50, automatic: true);

        $this->assertFalse($result['success']);
        $this->assertSame(['facebook'], $result['errors']);
        Http::assertSentCount(1);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unconfirmedWrites')]
    public function test_google_unknown_rejected_or_dry_run_writes_do_not_claim_changes(string $case): void
    {
        $campaign = $this->campaign();
        $before = $this->snapshot($campaign, 25_000_000);
        $after = $this->snapshot($campaign);
        if ($case === 'missing_before') {
            $before = null;
        } elseif ($case === 'missing_after') {
            $after = null;
        } elseif ($case === 'wrong_budget') {
            $after['daily_budget_micros'] = 30_000_000;
        } elseif ($case === 'wrong_resource') {
            $after['budget_resource'] = 'customers/3141592653/campaignBudgets/999';
        } elseif ($case === 'wrong_currency') {
            $after['currency_code'] = 'USD';
        }
        $writer = new ReceiptGoogleBudgetWriter($campaign->customer, [$before, $after], accepted: $case !== 'rejected');
        if ($case === 'dry_run') {
            $writer->dryRun();
        }

        $result = $writer->updateWithChanges('3141592653', $campaign->googleAdsResourceName(), 50_000_000);

        $this->assertSame([], $result['changes']);
        $this->assertSame($case === 'dry_run', $result['success']);
    }

    public static function unconfirmedWrites(): array
    {
        return array_map(fn ($case) => [$case], ['missing_before', 'missing_after', 'wrong_budget', 'wrong_resource', 'wrong_currency', 'rejected', 'dry_run']);
    }

    public function test_a_confirmed_google_change_survives_a_later_local_recording_failure(): void
    {
        Exceptions::fake();
        $campaign = $this->campaign();
        $this->instance(GoogleBudgetPacingHistory::class, new class extends GoogleBudgetPacingHistory
        {
            public function record(Campaign $campaign, array $readback): void
            {
                throw new \RuntimeException('History unavailable');
            }
        });
        $writer = new ReceiptGoogleBudgetWriter($campaign->customer, [$this->snapshot($campaign, 25_000_000), $this->snapshot($campaign)]);

        $result = $writer->updateWithChanges('3141592653', $campaign->googleAdsResourceName(), 50_000_000);

        $this->assertFalse($result['success']);
        $this->assertCount(1, $result['changes']);
        $this->assertSame(25_000_000, $result['changes'][0]['old_budget_micros']);
    }

    public function test_one_failed_target_does_not_erase_another_targets_confirmed_change(): void
    {
        $campaign = $this->campaign();
        foreach (['456', '457'] as $id) {
            Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'google_ads_campaign_id' => $id, 'daily_budget' => '25.00']);
        }
        $writer = new class implements ReportsBudgetChanges
        {
            public function updateDailyBudget(string $customerId, string $campaignResourceName, float $newDailyBudgetMicros): bool
            {
                return $this->updateDailyBudgetWithChanges($customerId, $campaignResourceName, $newDailyBudgetMicros)['success'];
            }

            public function updateDailyBudgetWithChanges(string $customerId, string $campaignResourceName, float $newDailyBudgetMicros): array
            {
                return str_ends_with($campaignResourceName, '/457') ? ['success' => false, 'changes' => []] : [
                    'success' => true, 'changes' => [['platform' => 'google', 'resource_name' => $campaignResourceName,
                        'old_budget_micros' => 10_000_000, 'new_budget_micros' => (int) $newDailyBudgetMicros, 'currency' => 'AUD']],
                ];
            }
        };
        $factory = new class($writer) extends CustomerRoutedAdsServiceFactory
        {
            public function __construct(private BudgetMutator $writer) {}

            public function budgets(Customer $customer): BudgetMutator
            {
                return $this->writer;
            }
        };

        $result = (new CampaignBudgetService($factory))->applyWithChanges($campaign, 50);

        $this->assertFalse($result['success']);
        $this->assertCount(1, $result['changes']);
        $this->assertSame(25_000_000, $result['changes'][0]['new_budget_micros']);
        $this->assertSame($campaign->googleAdsResourceName(), $result['changes'][0]['resource_name']);
    }

    public function test_meta_receipt_reads_the_account_currency_and_actual_daily_budget(): void
    {
        config(['services.facebook.system_user_token' => 'receipt-test-token']);
        $campaign = $this->campaign(['google_ads_campaign_id' => null, 'facebook_ads_campaign_id' => '456'], ['facebook_ads_account_id' => 'act_123']);
        Http::fake([
            'graph.facebook.com/*/act_123*' => Http::response(['id' => 'act_123', 'currency' => 'AUD']),
            'graph.facebook.com/*/456*' => Http::sequence()->push(['id' => '456', 'daily_budget' => '2500'])->push(['success' => true])->push(['id' => '456', 'daily_budget' => '5000']),
        ]);

        $result = app(CampaignBudgetService::class)->applyWithChanges($campaign, 50);

        $this->assertTrue($result['success']);
        $this->assertSame([['platform' => 'facebook', 'resource_name' => '456', 'old_budget_micros' => 25_000_000,
            'new_budget_micros' => 50_000_000, 'currency' => 'AUD']], $result['changes']);
    }

    public function test_meta_without_account_currency_still_reconciles_but_does_not_claim_a_change(): void
    {
        config(['services.facebook.system_user_token' => 'receipt-test-token']);
        $campaign = $this->campaign(['google_ads_campaign_id' => null, 'facebook_ads_campaign_id' => '456'], ['facebook_ads_account_id' => '123']);
        Http::fake([
            'graph.facebook.com/*/act_123*' => Http::response(['id' => 'act_123']),
            'graph.facebook.com/*/456*' => Http::sequence()->push(['id' => '456', 'daily_budget' => '2500'])->push(['success' => true])->push(['id' => '456', 'daily_budget' => '5000']),
        ]);

        $result = app(CampaignBudgetService::class)->applyWithChanges($campaign, 50);

        $this->assertSame(['success' => true, 'changes' => []], $result);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['daily_budget'] === 5000);
    }

    public function test_meta_ad_set_receipt_preserves_only_successfully_changed_shares(): void
    {
        config(['services.facebook.system_user_token' => 'receipt-test-token']);
        $campaign = $this->campaign(['google_ads_campaign_id' => null, 'facebook_ads_campaign_id' => '456'], ['facebook_ads_account_id' => '123']);
        Http::fake([
            'graph.facebook.com/*/act_123*' => Http::response(['id' => 'act_123', 'currency' => 'AUD']),
            'graph.facebook.com/*/456/adsets*' => Http::sequence()
                ->push(['data' => [['id' => '111', 'daily_budget' => '1000'], ['id' => '112', 'daily_budget' => '1000']]])
                ->push(['data' => [['id' => '111', 'daily_budget' => '2500'], ['id' => '112', 'daily_budget' => '1000']]]),
            'graph.facebook.com/*/456*' => Http::response(['id' => '456']),
            'graph.facebook.com/*/111*' => Http::response(['success' => true]),
            'graph.facebook.com/*/112*' => Http::response(['success' => false]),
        ]);

        $result = app(CampaignBudgetService::class)->applyWithChanges($campaign, 50);

        $this->assertFalse($result['success']);
        $this->assertSame([['platform' => 'facebook', 'resource_name' => '111', 'old_budget_micros' => 10_000_000,
            'new_budget_micros' => 25_000_000, 'currency' => 'AUD']], $result['changes']);
    }

    public function test_automatic_meta_ad_set_retry_uses_original_shares_after_one_write_fails(): void
    {
        config(['services.facebook.system_user_token' => 'receipt-test-token']);
        $campaign = $this->campaign(['status' => 'active', 'google_ads_campaign_id' => null, 'facebook_ads_campaign_id' => '456'], ['facebook_ads_account_id' => '123']);
        $rows = fn (int $first, int $second) => ['data' => [
            ['id' => '111', 'status' => 'ACTIVE', 'daily_budget' => (string) $first],
            ['id' => '112', 'status' => 'ACTIVE', 'daily_budget' => (string) $second],
        ]];
        Http::fake([
            'graph.facebook.com/*/act_123*' => Http::response(['id' => 'act_123', 'currency' => 'AUD']),
            'graph.facebook.com/*/456/adsets*' => Http::sequence()
                ->push($rows(1000, 1000))->push($rows(2500, 1000))
                ->push($rows(2500, 1000))->push($rows(2500, 2500)),
            'graph.facebook.com/*/456*' => Http::response(['id' => '456', 'account_id' => '123', 'status' => 'ACTIVE']),
            'graph.facebook.com/*/111*' => Http::sequence()->push(['success' => true]),
            'graph.facebook.com/*/112*' => Http::sequence()->push(['success' => false])->push(['success' => true]),
        ]);
        $service = app(CampaignBudgetService::class);

        $first = $service->applyWithChanges($campaign, 50, automatic: true);
        $retry = $service->applyWithChanges($campaign, 50, automatic: true);

        $this->assertFalse($first['success']);
        $this->assertSame(['111'], array_column($first['changes'], 'resource_name'));
        $this->assertTrue($retry['success']);
        $this->assertSame(['112'], array_column($retry['changes'], 'resource_name'));
        $this->assertSame([25_000_000], array_column($retry['changes'], 'new_budget_micros'));
    }

    public function test_linkedin_decimal_receipt_uses_remote_currency_and_confirmed_amounts(): void
    {
        config(['linkedinads.client_id' => 'test-id', 'linkedinads.client_secret' => 'test-secret', 'linkedinads.refresh_token' => 'test-refresh', 'linkedinads.defaults.currency' => 'AUD']);
        EnabledPlatform::updateOrCreate(['slug' => 'linkedin'], ['name' => 'LinkedIn', 'is_enabled' => true]);
        Cache::forget('enabled_platform_slugs');
        $campaign = $this->campaign(['google_ads_campaign_id' => null, 'linkedin_campaign_id' => '456'], ['linkedin_ads_account_id' => '123', 'currency_code' => 'AUD']);
        Http::fake([
            'www.linkedin.com/oauth/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'api.linkedin.com/rest/adCampaigns/456*' => Http::sequence()
                ->push(['id' => '456', 'account' => 'urn:li:sponsoredAccount:123', 'dailyBudget' => ['currencyCode' => 'AUD', 'amount' => '25.50']])
                ->push(['success' => true])
                ->push(['id' => '456', 'account' => 'urn:li:sponsoredAccount:123', 'dailyBudget' => ['currencyCode' => 'AUD', 'amount' => '50.00']]),
        ]);

        $result = app(CampaignBudgetService::class)->applyWithChanges($campaign, 50);

        $this->assertSame([['platform' => 'linkedin', 'resource_name' => '456', 'old_budget_micros' => 25_500_000,
            'new_budget_micros' => 50_000_000, 'currency' => 'AUD']], $result['changes']);
        $this->assertTrue($result['success']);
    }
}

class ReceiptGoogleBudgetWriter extends UpdateCampaignBudget
{
    public array $writes = [];

    public function __construct(Customer $customer, private array $snapshots, private bool $accepted = true)
    {
        $this->customer = $customer;
    }

    protected function ensureClient(): void {}

    protected function readBudget(string $customerId, string $campaignResourceName): ?array
    {
        return array_shift($this->snapshots);
    }

    protected function writeBudget(string $customerId, string $budgetResourceName, int $micros): bool
    {
        $this->writes[] = $micros;

        return $this->accepted;
    }
}
