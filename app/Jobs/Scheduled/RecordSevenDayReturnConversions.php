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
        // users.created_at, qualified: the relation is a belongsToMany, so the
        // generated query joins customer_user, and both tables carry created_at.
        // Unqualified, Postgres rejected the whole query as ambiguous and this
        // job failed every night — silently, until report() started reaching the
        // admin dashboard.
        Customer::whereHas('users', fn ($q) => $q->whereNotNull('gclid')
            ->whereBetween('users.created_at', [now()->subDays(7)->startOfDay(), now()->subDays(7)->endOfDay()])
        )->each(fn (Customer $c) => RecordSiteConversion::dispatch($c, 'seven_day_return'));
    }
}
