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

    /**
     * Some Google mutate endpoints have no validate_only field at all —
     * CustomerUserAccessInvitation takes customer_id and operation and nothing
     * else. Asked by reflection so the exemption disappears by itself if Google
     * adds the field. GoogleAdsMutationCallShapeTest holds those services to
     * returning before the call instead.
     */
    private static function cannotValidateOnly(string $requestClass): bool
    {
        $fqcn = 'Google\\Ads\\GoogleAds\\V22\\Services\\'.$requestClass;

        return class_exists($fqcn) && ! method_exists($fqcn, 'setValidateOnly');
    }

    private static function withoutComments(string $source): string
    {
        $source = (string) preg_replace('#/\*.*?\*/#s', ' ', $source);

        return (string) preg_replace('#^\s*//.*$#m', ' ', $source);
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

            /*
               Comments stripped first.

               This counted a raw substring over the whole file, so a comment
               that quoted "'validate_only' => \$this->dryRun" — explaining why
               some endpoint could not use it — satisfied the check on its own.
               A guard that prose can pass is not a guard.
            */
            $source = self::withoutComments(file_get_contents($file->getPathname()));
            preg_match_all('/new (Mutate[A-Za-z]*Request)\(/', $source, $matches);

            $requests = array_values(array_filter(
                $matches[1],
                fn (string $class) => ! self::cannotValidateOnly($class),
            ));

            if ($requests === []) {
                continue;
            }

            $flags = substr_count($source, "'validate_only' => \$this->dryRun");

            if ($flags < count($requests)) {
                $missing[] = basename($file->getPathname())." ({$flags}/".count($requests).')';
            }
        }

        $this->assertSame([], $missing, 'mutate requests without validate_only: '.implode(', ', $missing));
    }
}
