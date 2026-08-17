<?php

namespace Tests\Feature;

use App\Jobs\ProcessDailyAdSpendBilling;
use App\Models\AdSpendCredit;
use App\Models\Campaign;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A billing run that deducts nothing is only good news if there was nothing to
 * deduct.
 *
 * This job ran clean every morning for a week — "Completed daily billing run
 * {processed: 0}" — while AUD 54 of real spend accumulated unbilled. A run that
 * bills everybody and a run that bills nobody wrote the same INFO line, so the
 * weekly reconciliation was the first thing to notice, seven days later.
 *
 * The cause was the selection query: it matched campaigns on the local `status`
 * column while spend is driven by what the platform reports. Those two drifted
 * (BILL-8), so a campaign live on Google but not locally active spent without
 * ever being selected for billing.
 */
class BillingIdleDetectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Collect error-level log messages as they happen.
     *
     * Log::listen rather than a facade spy: the spy's assertions are Mockery
     * calls on a facade, which static analysis cannot type.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function captureErrors(): \Illuminate\Support\Collection
    {
        $errors = collect();

        Log::listen(function ($event) use ($errors) {
            if (in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
                $errors->push($event->message);
            }
        });

        return $errors;
    }

    private function customerWithCredit(): Customer
    {
        $customer = Customer::factory()->create();

        AdSpendCredit::create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'payment_status' => 'current',
            'current_balance' => 100,
            'initial_credit_amount' => 100,
        ]);

        return $customer;
    }

    public function test_a_campaign_live_on_the_platform_is_billed_even_if_not_locally_active(): void
    {
        // The exact drift that caused the miss: paused locally, enabled on
        // Google, spending money.
        $customer = $this->customerWithCredit();

        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'paused',
            'platform_status' => 'ENABLED',
        ]);

        // The job's own selection, not a copy of it — a duplicated query in a
        // test passes happily while the real one is wrong.
        $job = new ProcessDailyAdSpendBilling;
        $method = new \ReflectionMethod($job, 'eligibleCustomers');
        $method->setAccessible(true);

        $selected = $method->invoke($job, ['paused', 'failed', 'grace_period'])->pluck('id')->all();

        $this->assertContains($customer->id, $selected);
    }

    public function test_a_skipped_customer_does_not_raise_the_alarm(): void
    {
        // Already billed today is a legitimate reason to process nobody. The
        // run considered them, which is the distinction that matters.
        $customer = $this->customerWithCredit();

        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'draft',
            'platform_status' => 'ENABLED',
        ]);

        \Illuminate\Support\Facades\Cache::put("adspend_billed:{$customer->id}:".now()->toDateString(), true, 3600);

        $errors = $this->captureErrors();

        (new ProcessDailyAdSpendBilling)->handle(app(\App\Services\AdSpendBillingService::class));

        $this->assertSame([], $errors->all());
    }

    public function test_processing_nobody_while_a_campaign_is_live_raises_the_alarm(): void
    {
        // The backstop. The widened selection query should prevent this from
        // ever happening, but "should" is what the last seven days were built
        // on — if the run ever bills nobody while ads are live, it must say so
        // rather than log another clean line.
        $customer = $this->customerWithCredit();

        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'draft',
            'platform_status' => 'ENABLED',
        ]);

        $errors = $this->captureErrors();

        $job = new ProcessDailyAdSpendBilling;
        $method = new \ReflectionMethod($job, 'alertIfIdleWhileSpending');
        $method->setAccessible(true);
        $method->invoke($job, ['processed' => 0, 'successful' => 0, 'failed' => 0, 'skipped' => 0, 'total_spend' => 0]);

        $this->assertNotEmpty(
            $errors->filter(fn ($m) => str_contains($m, 'billed nobody while campaigns were live')),
            'a run that bills nobody while ads are live must say so'
        );
    }

    public function test_a_quiet_run_with_no_live_campaigns_raises_nothing(): void
    {
        // Most days there is genuinely nothing to bill, and an alert every
        // morning would train people to ignore it.
        $this->customerWithCredit();

        $errors = $this->captureErrors();

        (new ProcessDailyAdSpendBilling)->handle(app(\App\Services\AdSpendBillingService::class));

        $this->assertSame([], $errors->all());
    }
}
