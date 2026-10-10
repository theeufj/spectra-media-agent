<?php

namespace Tests\Feature;

use App\Jobs\OptimizeCampaigns;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Plan;
use App\Services\Agents\CampaignOptimizationAgent;
use App\Services\Agents\FacebookAdRelevanceDiagnosticsAgent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OptimizerCadenceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->travelTo(Carbon::parse('2026-10-10 00:00:00'));
    }

    public function test_daily_plans_run_even_when_yesterdays_completion_was_seconds_later(): void
    {
        $growth = $this->campaign('growth', now()->subDay()->addSeconds(59));
        $agency = $this->campaign('agency', now()->subDay()->addMinutes(3));
        $alreadyToday = $this->campaign('growth', now());
        $new = $this->campaign('free', null);
        $pending = $this->campaign('growth', null, 'PENDING');

        $analyzed = $this->runOptimizer();

        foreach ([$growth, $agency, $new] as $campaign) {
            $this->assertContains($campaign->id, $analyzed);
            $this->assertTrue($campaign->fresh()->last_optimized_at->isSameDay(now()));
        }
        $this->assertNotContains($alreadyToday->id, $analyzed);
        $this->assertNotContains($pending->id, $analyzed);
        $this->assertNotContains($growth->id, $this->runOptimizer(), 'Repeated jobs must not analyze the same campaign twice today.');
    }

    public function test_free_and_starter_retain_a_seven_calendar_day_cadence(): void
    {
        $freeDue = $this->campaign('free', now()->subDays(7)->addMinutes(5));
        $starterDue = $this->campaign('starter', now()->subDays(7)->addSeconds(59));
        $freeRecent = $this->campaign('free', now()->subDays(6));
        $starterRecent = $this->campaign('starter', now()->subDay());

        $analyzed = $this->runOptimizer();

        $this->assertContains($freeDue->id, $analyzed);
        $this->assertContains($starterDue->id, $analyzed);
        $this->assertNotContains($freeRecent->id, $analyzed);
        $this->assertNotContains($starterRecent->id, $analyzed);
        $this->assertFalse($freeRecent->fresh()->last_optimized_at->isSameDay(now()));
    }

    private function campaign(string $plan, ?Carbon $lastOptimized, string $primaryStatus = 'ELIGIBLE'): Campaign
    {
        $customer = Customer::factory()->create(['plan_id' => Plan::where('slug', $plan)->value('id')]);

        return Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => '123',
            'primary_status' => $primaryStatus, 'last_optimized_at' => $lastOptimized]);
    }

    /** @return list<int> */
    private function runOptimizer(): array
    {
        $analyzed = [];
        $agent = $this->createMock(CampaignOptimizationAgent::class);
        $agent->method('analyze')->willReturnCallback(function (Campaign $campaign) use (&$analyzed) {
            $analyzed[] = $campaign->id;

            return null;
        });
        $diagnostics = $this->createMock(FacebookAdRelevanceDiagnosticsAgent::class);
        (new OptimizeCampaigns)->handle($agent, $diagnostics);

        return $analyzed;
    }
}
