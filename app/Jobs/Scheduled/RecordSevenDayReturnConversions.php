<?php

namespace App\Jobs\Scheduled;

use App\Jobs\RecordSiteGoogleConversion;
use App\Models\FeatureUsageDaily;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Upload the seven-day return conversion for customers whose signup click
 * carried a Google click identifier (gclid, or gbraid/wbraid under iOS ATT).
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
        // Scan completed retention windows. Each event belongs to the person who
        // used the product and has one stable occurrence timestamp across retries.
        User::whereBetween('created_at', [now()->subDays(10), now()->subDays(7)])
            ->where(fn ($query) => $query->whereNotNull('gclid')->orWhereNotNull('gbraid')->orWhereNotNull('wbraid'))
            ->eachById(function (User $user) {
                $start = $user->created_at->copy()->addDays(7);
                $usage = FeatureUsageDaily::where('user_id', $user->id)->where('last_at', '>=', $start)
                    ->where('last_at', '<', $start->copy()->addDay())->orderBy('last_at')->first();
                if ($usage) {
                    $key = 'seven_day_return:'.$user->id.':'.$user->created_at->timestamp;
                    $record = \App\Models\SpectraConversionEvent::firstOrCreate(['deduplication_key' => $key], [
                        'user_id' => $user->id, 'event' => 'seven_day_return', 'occurred_at' => $usage->last_at,
                        'mode' => 'server_google', 'ad_identifiers' => $user->googleAdIdentifiers(),
                        'gclid' => $user->gclid, 'value' => 0, 'currency' => 'USD',
                    ]);
                    if (! $record->uploaded_to_google) {
                        RecordSiteGoogleConversion::dispatch($user, 'seven_day_return', $record->occurred_at, $record->id);
                    }
                }
            });
    }
}
