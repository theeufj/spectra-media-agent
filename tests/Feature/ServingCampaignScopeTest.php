<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Is this campaign delivering?" has one answer, and it lives on the model.
 *
 * Campaign::SERVING_PRIMARY_STATUSES includes LIMITED — a campaign constrained
 * by its budget is still serving impressions. Six nightly jobs spelled the list
 * out by hand as ['ELIGIBLE', 'LEARNING'] and dropped it, so a budget-limited
 * campaign was skipped by the budget optimiser, the self-healer, the wasteful
 * ad-group sweep and the A/B test starter — the jobs it most needed. Nothing
 * failed; each run just quietly covered fewer campaigns than it reported.
 */
class ServingCampaignScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_limited_campaigns_count_as_serving(): void
    {
        $customer = Customer::factory()->create();

        foreach (['ELIGIBLE', 'LIMITED', 'LEARNING', 'PAUSED', 'PENDING'] as $status) {
            Campaign::factory()->create([
                'customer_id' => $customer->id,
                'primary_status' => $status,
            ]);
        }

        $serving = Campaign::withoutGlobalScopes()->serving()->pluck('primary_status')->sort()->values()->all();

        $this->assertSame(['ELIGIBLE', 'LEARNING', 'LIMITED'], $serving);
    }

    public function test_no_job_spells_the_serving_list_out_by_hand(): void
    {
        $offenders = [];

        foreach (glob(base_path('app/Jobs/*.php')) as $file) {
            $src = (string) file_get_contents($file);

            // Any primary_status filter that is not the model's own constant.
            if (preg_match("/whereIn\(\s*'primary_status'\s*,\s*\[/", $src)
                && ! str_contains($src, 'SERVING_PRIMARY_STATUSES')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'These jobs filter primary_status by hand instead of using '
            .'Campaign::serving(), so they drift from SERVING_PRIMARY_STATUSES: '.implode(', ', $offenders));
    }
}
