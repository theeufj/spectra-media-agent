<?php

namespace App\Jobs\Scheduled;

use App\Jobs\GenerateExecutiveReport;
use App\Models\Campaign;
use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Build the Monday morning executive report for every customer with a live campaign.
 *
 * Was a Schedule::call() closure. Those run inside the scheduler tick: the
 * query, the fan-out and any HTTP all happen synchronously in that one process,
 * with no tries, no timeout, and no route to the admin exception dashboard —
 * and withoutOverlapping() then locks out the next run while it hangs. As a
 * queued job it retries, times out, and a failure reaches Queue::failing.
 */
class GenerateWeeklyExecutiveReports implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function handle(): void
    {
        Customer::whereHas('campaigns', function (Builder $q) {
            /** @var Builder<Campaign> $q */
            $q->withDeployedPlatforms();
        })->each(function ($customer) {
            GenerateExecutiveReport::dispatch($customer->id, 'weekly');
        });
    }
}
