<?php

namespace App\Jobs\Scheduled;

use App\Jobs\RecordSiteConversion;
use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Upload the seven-day return conversion for customers whose signup click carried a gclid.
 *
 * Was a Schedule::call() closure. Those run inside the scheduler tick: the
 * query, the fan-out and any HTTP all happen synchronously in that one process,
 * with no tries, no timeout, and no route to the admin exception dashboard —
 * and withoutOverlapping() then locks out the next run while it hangs. As a
 * queued job it retries, times out, and a failure reaches Queue::failing.
 */
class RecordSevenDayReturnConversions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function handle(): void
    {
        Customer::whereHas('users', fn ($q) => $q->whereNotNull('gclid')
            ->whereBetween('created_at', [now()->subDays(7)->startOfDay(), now()->subDays(7)->endOfDay()])
        )->each(fn (Customer $c) => RecordSiteConversion::dispatch($c, 'seven_day_return'));
    }
}
