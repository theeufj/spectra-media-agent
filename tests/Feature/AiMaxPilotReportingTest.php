<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use App\Services\GoogleAds\GetAiMaxPilotPerformance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

class AiMaxPilotReportingTest extends TestCase
{
    use DatabaseTransactions;

    private function fixture(): Campaign
    {
        config(['cache.default' => 'array']);
        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $customer->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);

        return Campaign::factory()->create(['customer_id' => $customer->id,
            'spend_guardrails' => ['ai_max_pilot' => ['enabled' => true]],
            'search_delivery_state' => ['ai_max_setup' => ['activated_at' => now()->subDays(2)->toIso8601String()]]]);
    }

    public function test_authorized_reporting_is_cached_and_does_not_change_campaign_state(): void
    {
        $campaign = $this->fixture();
        $before = $campaign->fresh()->getAttributes();
        $report = ['status' => 'observed', 'metrics' => ['cost_micros' => 5_000_000, 'conversions' => 0, 'cpa_micros' => null]];
        $provider = $this->createMock(GetAiMaxPilotPerformance::class);
        $provider->expects($this->once())->method('inspect')->willReturn($report);
        $this->app->bind(GetAiMaxPilotPerformance::class, fn () => $provider);

        foreach ([1, 2] as $attempt) {
            $this->getJson(route('api.campaigns.show', $campaign))->assertOk()
                ->assertJsonPath('ai_max_performance.status', 'observed')
                ->assertJsonPath('ai_max_performance.metrics.conversions', 0)
                ->assertJsonPath('ai_max_performance.metrics.cpa_micros', null);
        }
        $this->assertSame($before, $campaign->fresh()->getAttributes());
    }

    public function test_cross_customer_requests_cannot_read_google_performance(): void
    {
        $this->fixture();
        $foreign = Campaign::factory()->create();
        $provider = $this->createMock(GetAiMaxPilotPerformance::class);
        $provider->expects($this->never())->method('inspect');
        $this->app->bind(GetAiMaxPilotPerformance::class, fn () => $provider);

        $this->getJson(route('api.campaigns.show', $foreign))->assertNotFound();
    }

    public function test_a_reporting_failure_cannot_break_the_campaign_page_or_claim_zero_sales(): void
    {
        Exceptions::fake();
        $campaign = $this->fixture();
        $provider = $this->createMock(GetAiMaxPilotPerformance::class);
        $provider->expects($this->once())->method('inspect')->willThrowException(new \RuntimeException('report unavailable'));
        $this->app->bind(GetAiMaxPilotPerformance::class, fn () => $provider);

        $this->getJson(route('api.campaigns.show', $campaign))->assertOk()
            ->assertJsonPath('ai_max_performance.status', 'unavailable')
            ->assertJsonPath('ai_max_performance.metrics', null);
        Exceptions::assertReported(\RuntimeException::class);
    }
}
