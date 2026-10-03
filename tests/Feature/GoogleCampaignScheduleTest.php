<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\Google\GoogleCampaignSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GoogleCampaignScheduleTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_google_receives_the_customer_approved_dates(): void
    {
        CarbonImmutable::setTestNow('2026-09-22 10:00:00');
        $campaign = Campaign::factory()->create([
            'start_date' => '2026-09-23',
            'end_date' => '2026-09-30',
        ]);

        $this->assertSame([
            'startDate' => '2026-09-23',
            'endDate' => '2026-09-30',
        ], GoogleCampaignSchedule::for($campaign));
    }

    public function test_a_delayed_deployment_starts_today_without_extending_the_end_date(): void
    {
        CarbonImmutable::setTestNow('2026-09-25 10:00:00');
        $customer = Customer::factory()->create(['timezone' => 'Australia/Sydney']);
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'start_date' => '2026-09-23',
            'end_date' => '2026-09-30',
        ]);

        $this->assertSame([
            'startDate' => '2026-09-25',
            'endDate' => '2026-09-30',
        ], GoogleCampaignSchedule::for($campaign));
    }

    public function test_expired_campaign_cannot_be_deployed_with_a_new_arbitrary_year(): void
    {
        CarbonImmutable::setTestNow('2026-10-03 10:00:00');
        $campaign = Campaign::factory()->create([
            'start_date' => '2026-09-23',
            'end_date' => '2026-09-30',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Campaign end date has passed');
        GoogleCampaignSchedule::for($campaign);
    }

    public function test_every_google_executor_uses_the_shared_schedule(): void
    {
        foreach (glob(app_path('Services/Agents/Google/Executors/*CampaignExecutor.php')) as $file) {
            $source = file_get_contents($file);
            $this->assertStringContainsString('GoogleCampaignSchedule::for($campaign)', $source, basename($file));
            $this->assertStringNotContainsString("'endDate' => now()->addYear()", $source, basename($file));
        }
    }
}
