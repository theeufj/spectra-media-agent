<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RunHealthChecks;
use App\Services\Agents\HealthCheckAgent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group jobs
 */
class RunHealthChecksTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_INTEGRATION_TESTS=true to run.');
        }
    }

    public function test_job_has_correct_configuration(): void
    {
        $job = new RunHealthChecks();

        $this->assertSame(3, $job->tries);
        $this->assertSame(1800, $job->timeout);
        $this->assertSame([60, 300], $job->backoff);
    }

    public function test_job_handle_runs_without_exception(): void
    {
        $job = new RunHealthChecks();
        $job->handle(app(HealthCheckAgent::class));

        $this->assertTrue(true);
    }
}
