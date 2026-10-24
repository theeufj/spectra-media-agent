<?php

namespace Tests\Feature;

use App\Contracts\Ads\BudgetMutator;
use App\Contracts\Ads\ReportsBudgetChanges;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Strategy;
use App\Services\Ads\CustomerRoutedAdsServiceFactory;
use App\Services\Campaigns\CampaignBudgetService;
use App\Services\Campaigns\GoogleBudgetPacingHistory;
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

    public function test_google_same_value_write_has_success_without_a_change_receipt(): void
    {
        $campaign = $this->campaign();
        $writer = new ReceiptGoogleBudgetWriter($campaign->customer, [$this->snapshot($campaign), $this->snapshot($campaign)]);

        $result = $writer->updateWithChanges('3141592653', $campaign->googleAdsResourceName(), 50_000_000);

        $this->assertSame(['success' => true, 'changes' => []], $result);
        $this->assertSame([50_000_000], $writer->writes);
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

    public function test_linkedin_decimal_receipt_uses_remote_currency_and_confirmed_amounts(): void
    {
        config(['linkedinads.client_id' => 'test-id', 'linkedinads.client_secret' => 'test-secret', 'linkedinads.refresh_token' => 'test-refresh', 'linkedinads.defaults.currency' => 'AUD']);
        EnabledPlatform::updateOrCreate(['slug' => 'linkedin'], ['name' => 'LinkedIn', 'is_enabled' => true]);
        Cache::forget('enabled_platform_slugs');
        $campaign = $this->campaign(['google_ads_campaign_id' => null, 'linkedin_campaign_id' => '456'], ['linkedin_ads_account_id' => '123']);
        Http::fake([
            'www.linkedin.com/oauth/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'api.linkedin.com/rest/adCampaigns/456*' => Http::sequence()
                ->push(['id' => '456', 'dailyBudget' => ['currencyCode' => 'AUD', 'amount' => '25.50']])
                ->push(['success' => true])
                ->push(['id' => '456', 'dailyBudget' => ['currencyCode' => 'AUD', 'amount' => '50.00']]),
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
