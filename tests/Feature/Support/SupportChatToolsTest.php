<?php

namespace Tests\Feature\Support;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Reporting\CrossPlatformAnalyticsService;
use App\Services\Support\SupportChatTools;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportChatToolsTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $customer;

    private Customer $otherCustomer;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Customer::unsetEventDispatcher();

        $this->customer = Customer::factory()->create(['name' => 'Ours']);
        $this->otherCustomer = Customer::factory()->create(['name' => 'Theirs']);
    }

    private function tools(?Customer $for = null): SupportChatTools
    {
        return new SupportChatTools(
            $for ?? $this->customer,
            app(CrossPlatformAnalyticsService::class),
        );
    }

    // ── Tenant isolation ─────────────────────────────────────────────────────

    public function test_no_tool_accepts_an_account_identifier(): void
    {
        // The isolation guarantee is structural, not a matter of the model
        // behaving. If a tool ever gains a customer_id/account_id/campaign_id
        // parameter, a customer typing "show me DigitalAF's spend" becomes one
        // convincing message away from a cross-tenant read.
        foreach (SupportChatTools::declarations() as $tool) {
            $params = array_keys((array) ($tool['parameters']['properties'] ?? []));

            foreach ($params as $param) {
                $this->assertNotContains(
                    $param,
                    ['customer_id', 'account_id', 'campaign_id', 'customer', 'account', 'user_id', 'email'],
                    "tool {$tool['name']} exposes an identifier parameter: {$param}",
                );
            }
        }
    }

    public function test_tools_read_only_the_bound_customer_even_when_asked_otherwise(): void
    {
        Campaign::factory()->count(2)->create(['customer_id' => $this->customer->id]);
        Campaign::factory()->count(7)->create(['customer_id' => $this->otherCustomer->id]);

        // The model supplying another account's id must change nothing: the
        // argument is not part of any declared schema and is simply ignored.
        $result = $this->tools()->handle('get_account_overview', [
            'customer_id' => $this->otherCustomer->id,
            'account' => 'Theirs',
        ]);

        $this->assertSame('Ours', $result['account_name']);
        $this->assertSame(2, $result['campaigns_total']);
    }

    public function test_campaign_listing_never_leaks_another_account(): void
    {
        Campaign::factory()->create(['customer_id' => $this->customer->id, 'name' => 'Our campaign']);
        Campaign::factory()->create(['customer_id' => $this->otherCustomer->id, 'name' => 'Their campaign']);

        $result = $this->tools()->handle('list_campaigns', ['customer_id' => $this->otherCustomer->id]);

        $names = array_column($result['campaigns'], 'name');
        $this->assertContains('Our campaign', $names);
        $this->assertNotContains('Their campaign', $names);
    }

    public function test_performance_is_scoped_to_the_bound_customer(): void
    {
        $ours = Campaign::factory()->create(['customer_id' => $this->customer->id]);
        $theirs = Campaign::factory()->create(['customer_id' => $this->otherCustomer->id]);

        foreach ([[$ours->id, 100, 10.0], [$theirs->id, 9999, 5000.0]] as [$campaignId, $impressions, $cost]) {
            DB::table('google_ads_performance_data')->insert([
                'campaign_id' => $campaignId,
                'date' => now()->subDay()->toDateString(),
                'impressions' => $impressions,
                'clicks' => 5,
                'cost' => $cost,
                'conversions' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $result = $this->tools()->handle('get_performance_summary', ['days' => 30]);

        $this->assertSame(100, (int) $result['totals']['impressions'], "another account's spend leaked into the totals");
        $this->assertSame(10.0, (float) $result['totals']['cost']);
    }

    // ── Behaviour ────────────────────────────────────────────────────────────

    public function test_an_account_with_no_data_says_so_rather_than_returning_zeros(): void
    {
        $result = $this->tools()->handle('get_performance_summary', []);

        // Zeros presented as a result read as "your ads performed terribly".
        // has_data lets the model say "nothing recorded yet" instead.
        $this->assertFalse($result['has_data']);
    }

    public function test_the_look_back_window_is_clamped(): void
    {
        $tenYears = $this->tools()->handle('get_performance_summary', ['days' => 3650]);
        $negative = $this->tools()->handle('get_performance_summary', ['days' => -5]);

        // Clamped, not rejected: a model asking for 3,650 days is a reason to
        // read 90, not a reason to fail the customer's question.
        $this->assertSame(90, $tenYears['period_days']);
        $this->assertSame(1, $negative['period_days']);
    }

    public function test_an_unknown_tool_returns_an_error_not_fabricated_data(): void
    {
        $result = $this->tools()->handle('delete_all_campaigns', []);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Unknown tool', $result['error']);
    }

    public function test_the_overview_reports_serving_state_from_the_platform(): void
    {
        Campaign::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'active',
            'primary_status' => 'MISCONFIGURED',
        ]);

        $result = $this->tools()->handle('get_account_overview', []);

        // We think it is active; the platform says it is not serving. The
        // customer asking "why aren't my ads running" needs the second answer.
        $this->assertSame(1, $result['campaigns_total']);
        $this->assertSame(0, $result['campaigns_live']);
    }

    public function test_connected_platforms_reflect_the_account(): void
    {
        $this->customer->update([
            'google_ads_customer_id' => '123-456-7890',
            'facebook_ads_account_id' => 'act_999',
        ]);

        $result = $this->tools()->handle('get_account_overview', []);

        $this->assertSame(['Google Ads', 'Facebook Ads'], $result['connected_platforms']);
    }

    public function test_a_failing_tool_returns_an_error_rather_than_throwing(): void
    {
        // A tool exception escaping mid-conversation costs the customer their
        // whole reply for a question the assistant could still partly answer.
        $tools = new SupportChatTools(
            $this->customer,
            new class extends CrossPlatformAnalyticsService
            {
                public function getSummary(Customer $customer, int $days = 30): array
                {
                    throw new \RuntimeException('analytics exploded');
                }
            },
        );

        $result = $tools->handle('get_performance_summary', []);

        $this->assertArrayHasKey('error', $result);
    }
}
