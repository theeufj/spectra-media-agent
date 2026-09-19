<?php

namespace Tests\Feature;

use App\Jobs\ProcessDailyAdSpendBilling;
use App\Models\AdSpendCredit;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\AdSpendBillingService;
use App\Services\Campaigns\CampaignBudgetService;
use App\Services\Campaigns\CollateralPlan;
use App\Services\Campaigns\StrategyDocument;
use App\Services\Crawling\PublicWebsiteFetcher;
use App\Services\Creative\ImageComposer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AuditWorkflowRegressionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_billing_limit_survives_peak_hour_and_platform_rounding_preserves_total(): void
    {
        $campaign = Campaign::factory()->create(['daily_budget' => 100, 'approved_daily_budget' => 100, 'billing_budget_multiplier' => 0.5]);
        foreach (['Google Ads', 'Facebook Ads', 'Microsoft Ads'] as $platform) {
            Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => $platform, 'daily_budget' => 33.33]);
        }
        $shares = app(CampaignBudgetService::class)->allocations($campaign, 200);
        $this->assertSame(5000, array_sum($shares));
        $this->assertSame([1667, 1667, 1666], array_values($shares));
        $this->assertSame(5000, array_sum(CampaignBudgetService::split(5000, ['one' => 40, 'two' => 60])));
    }

    public function test_a_crashed_billing_lease_is_recoverable_but_a_live_lease_is_not(): void
    {
        $customer = Customer::factory()->create();
        $job = new ProcessDailyAdSpendBilling;
        $claim = new \ReflectionMethod($job, 'claimBilling');
        $this->assertTrue($claim->invoke($job, $customer, '2026-09-17'));
        $this->assertFalse($claim->invoke(new ProcessDailyAdSpendBilling, $customer, '2026-09-17'));
        DB::table('ad_spend_billing_runs')->where('customer_id', $customer->id)->update(['lease_expires_at' => now()->subMinute()]);
        $replacement = new ProcessDailyAdSpendBilling;
        $this->assertTrue($claim->invoke($replacement, $customer, '2026-09-17'));
        // The old worker cannot complete the replacement's claim.
        (new \ReflectionMethod($job, 'finishBilling'))->invoke($job, $customer, '2026-09-17');
        $this->assertSame('processing', DB::table('ad_spend_billing_runs')->where('customer_id', $customer->id)->value('status'));
    }

    public function test_a_failed_spend_day_is_retried_even_when_the_customer_is_no_longer_active(): void
    {
        $customer = Customer::factory()->create(['timezone' => 'Australia/Sydney']);
        AdSpendCredit::create(['customer_id' => $customer->id, 'current_balance' => 100, 'initial_credit_amount' => 100, 'status' => 'active', 'payment_status' => 'current']);
        $day = now()->subDays(4)->toDateString();
        DB::table('ad_spend_billing_runs')->insert(['customer_id' => $customer->id, 'billing_date' => $day, 'spend_date' => $day, 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
        $billing = new class extends AdSpendBillingService
        {
            public array $days = [];

            public function processBillingForDate(Customer $customer, string $spendDate): array
            {
                $this->days[] = $spendDate;

                return ['success' => true, 'actual_spend' => 10, 'action_taken' => 'Deducted'];
            }
        };
        (new ProcessDailyAdSpendBilling)->handle($billing);
        $this->assertContains($day, $billing->days);
        $this->assertSame('completed', DB::table('ad_spend_billing_runs')->where('customer_id', $customer->id)->where('spend_date', $day)->value('status'));
    }

    public function test_a_public_redirect_to_metadata_is_blocked_before_a_second_request(): void
    {
        Http::fake(['https://public.example/' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);
        $fetcher = new class extends PublicWebsiteFetcher
        {
            protected function addresses(string $url): array
            {
                return $url === 'https://public.example/' ? ['8.8.8.8'] : parent::addresses($url);
            }
        };
        try {
            $fetcher->get('https://public.example/');
            $this->fail('The metadata redirect must be blocked.');
        } catch (\InvalidArgumentException) {
            Http::assertSentCount(1);
        }
    }

    public function test_explicit_video_false_wins_over_a_long_actionable_brief(): void
    {
        $campaign = Campaign::factory()->create();
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'generate_video' => false, 'video_strategy' => str_repeat('Show the product in action. ', 15)]);
        $this->assertFalse(app(CollateralPlan::class)->wantsVideo($campaign, $strategy));
    }

    public function test_strategy_document_rejects_a_partial_or_disabled_platform_set(): void
    {
        $this->expectException(ValidationException::class);
        StrategyDocument::fromArray(['strategies' => [['platform' => 'Facebook Ads']]], ['Google Ads'], 100);
    }

    public function test_search_assets_have_no_composited_copy_and_other_sets_include_a_clean_concept(): void
    {
        $composer = new ImageComposer;
        foreach ([0, 1, 2] as $slot) {
            $this->assertSame('clean', $composer->layout('Google Ads (SEM)', $slot));
            $this->assertSame('clean', $composer->layout('Google Ads', $slot));
        }
        $this->assertSame('clean', $composer->layout('Google Ads (Performance Max)', 0));
        $this->assertSame('headline', $composer->layout('Facebook Ads', 1));
        $this->assertSame('signature', $composer->layout('Facebook Ads', 2));
    }
}
