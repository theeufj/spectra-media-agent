<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ExceptionLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

/**
 * report() must reach the admin exception dashboard.
 *
 * The reporter in bootstrap/app.php wrote to App\Models\RuntimeException — a
 * class that does not exist; the model is ExceptionLog. The resulting Error was
 * swallowed by the handler's own catch, so for months every report() in the
 * codebase wrote nothing, and runtime_exceptions only ever received whole-job
 * failures via Queue::failing. bootstrap/ sat outside the PHPStan paths, so
 * neither phpstan nor bin/check-fatal-classes could see it.
 *
 * CLAUDE.md tells people to call report($e) alongside Log::error on money and
 * deploy paths precisely so those failures surface. These pin that promise.
 */
class ExceptionReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_writes_a_row_to_the_dashboard(): void
    {
        $before = ExceptionLog::count();

        report(new \RuntimeException('probe'));

        $this->assertSame($before + 1, ExceptionLog::count());

        $row = ExceptionLog::latest('id')->first();
        $this->assertSame(\RuntimeException::class, $row->type);
        $this->assertSame('probe', $row->message);
    }

    public function test_an_error_is_reported_not_just_an_exception(): void
    {
        // The whole point of catching \Throwable on the money paths: a TypeError
        // has to reach the dashboard the same way an Exception does.
        report(new \TypeError('bad type'));

        $this->assertSame(\TypeError::class, ExceptionLog::latest('id')->first()->type);
    }

    public function test_a_report_is_attributed_to_the_customer_in_context(): void
    {
        // Queue workers have no session, which is where most reports come from.
        $customer = Customer::factory()->create();

        Context::add('customer_id', $customer->id);

        try {
            report(new \RuntimeException('during a batch job'));
        } finally {
            Context::forget('customer_id');
        }

        $this->assertSame($customer->id, ExceptionLog::latest('id')->first()->customer_id);
    }

    public function test_a_report_outside_any_context_still_records(): void
    {
        report(new \RuntimeException('no context at all'));

        $row = ExceptionLog::latest('id')->first();

        $this->assertSame('no context at all', $row->message);
        $this->assertNull($row->customer_id);
    }
}
