<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\AddKeyword;
use App\Services\GoogleAds\CommonServices\AddNegativeKeyword;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBudget;
use App\Services\GoogleAds\CreateCampaignBudget;
use App\Services\GoogleAds\Exclusions\ExcludePlacements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * validate_only lets a real mutation be checked by Google without being applied.
 *
 * It was previously wired into DataManagerService only. Every other mutate path
 * — campaign creation, keywords, budgets, placement exclusions — could only be
 * tested by actually doing it, which on a live account means spending money to
 * discover a malformed request.
 *
 * The safety property that matters most is the default: a service must be live
 * unless dry run is explicitly asked for. A dry run that silently went live
 * would be far worse than having none, so these tests pin the default off, the
 * opt-in on, and the absence of any global switch that could flip it.
 */
class GoogleAdsDryRunTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::factory()->create(['google_ads_customer_id' => '1234567890']);
    }

    public function test_services_are_live_by_default(): void
    {
        // The important direction. Anything that defaults to dry run would
        // silently stop applying real changes.
        $customer = $this->customer();

        $this->assertFalse((new CreateCampaignBudget($customer))->isDryRun());
        $this->assertFalse((new AddKeyword($customer))->isDryRun());
        $this->assertFalse((new AddNegativeKeyword($customer))->isDryRun());
        $this->assertFalse((new UpdateCampaignBudget($customer))->isDryRun());
        $this->assertFalse((new ExcludePlacements($customer))->isDryRun());
    }

    public function test_dry_run_is_opt_in_and_fluent(): void
    {
        $service = (new AddKeyword($this->customer()))->dryRun();

        $this->assertInstanceOf(AddKeyword::class, $service);
        $this->assertTrue($service->isDryRun());
    }

    public function test_dry_run_can_be_turned_back_off(): void
    {
        $service = (new AddKeyword($this->customer()))->dryRun()->dryRun(false);

        $this->assertFalse($service->isDryRun());
    }

    public function test_the_flag_does_not_leak_between_instances(): void
    {
        // Per-instance is the whole point: no global mode that one caller can
        // leave switched on for another, or that a queue worker inherits.
        $customer = $this->customer();

        $dry = (new AddKeyword($customer))->dryRun();
        $live = new AddKeyword($customer);

        $this->assertTrue($dry->isDryRun());
        $this->assertFalse($live->isDryRun());
    }

    public function test_every_mutate_request_passes_the_flag_through(): void
    {
        // Guards the mechanical sweep: a mutate added later without
        // 'validate_only' => $this->dryRun would silently ignore dry run and
        // apply a change someone believed was only being validated.
        $dir = app_path('Services/GoogleAds');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        $missing = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $requests = preg_match_all('/new Mutate[A-Za-z]*Request\(/', $source);

            if ($requests === 0) {
                continue;
            }

            $flags = substr_count($source, "'validate_only' => \$this->dryRun");

            if ($flags < $requests) {
                $missing[] = basename($file->getPathname())." ({$flags}/{$requests})";
            }
        }

        $this->assertSame([], $missing, 'mutate requests without validate_only: '.implode(', ', $missing));
    }
}
