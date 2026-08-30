<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Nothing substantial runs inside the scheduler tick.
 *
 * Sixteen entries were Schedule::call() closures, and a closure runs
 * synchronously in the scheduler process: the query, the fan-out and — for the
 * OpenRouter credit check — a live HTTP call, all in one tick, with no tries,
 * no timeout, and no route to the admin exception dashboard, which is fed by
 * Queue::failing. Worse, withoutOverlapping() then holds the lock while it
 * hangs, so one slow tick suppresses every following run.
 */
class ScheduledFanOutJobsTest extends TestCase
{
    public function test_no_scheduled_entry_runs_a_closure_in_the_tick(): void
    {
        // Read the source rather than the built schedule: Schedule::job() is
        // also a CallbackEvent under the hood, so the two are indistinguishable
        // once registered. The distinction that matters is the one written down.
        $source = file_get_contents(base_path('routes/console.php'));

        $this->assertStringNotContainsString('Schedule::call(', $source, implode("\n", [
            'A scheduled closure runs inside the scheduler process: its query,',
            'its fan-out and any HTTP happen synchronously in one tick, with no',
            'tries, no timeout, and no route to the admin exception dashboard',
            '(fed by Queue::failing). withoutOverlapping() then holds the lock',
            'while it hangs, suppressing every following run.',
            '',
            'Move the body into a job under App\\Jobs\\Scheduled and dispatch it',
            'with Schedule::job().',
        ]));
    }

    public function test_every_scheduled_fan_out_job_sets_tries_and_timeout(): void
    {
        $missing = [];

        foreach (glob(app_path('Jobs/Scheduled/*.php')) as $file) {
            $class = 'App\\Jobs\\Scheduled\\'.basename($file, '.php');
            $job = new $class;

            if (! isset($job->tries) || ! isset($job->timeout)) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, 'a queued job with no tries or timeout can hang a worker indefinitely');
    }

    public function test_the_fan_out_jobs_are_discoverable(): void
    {
        // Guard on the guard: an empty directory would make the above vacuous.
        $this->assertGreaterThanOrEqual(16, count(glob(app_path('Jobs/Scheduled/*.php'))));
    }
}
