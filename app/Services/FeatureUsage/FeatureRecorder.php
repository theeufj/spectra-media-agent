<?php

namespace App\Services\FeatureUsage;

use App\Enums\ProductFeature;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records that a feature was used.
 *
 * Static, following App\Services\ActivityLogger and AgentActivity::record() —
 * the two existing recorders in this codebase.
 *
 * Two rules govern everything here:
 *
 *  1. THIS MUST NEVER BREAK A REQUEST. It runs inside user-facing page loads,
 *     including /dashboard. Analytics that takes the dashboard down is strictly
 *     worse than no analytics, so every failure is caught, reported and
 *     swallowed. \Throwable, not \Exception — a TypeError from a bad argument
 *     sails straight past a handler that only catches \Exception.
 *  2. NO QUEUE. QUEUE_CONNECTION is sync here, so queueing would be a lie in
 *     development and, in production, would turn one cheap indexed upsert into
 *     serialise + push + dequeue + the same upsert. The daily-counter shape is
 *     what makes the synchronous write affordable; config('feature_usage.enabled')
 *     is the escape hatch if it ever is not.
 */
class FeatureRecorder
{
    /**
     * Increment today's counter for a feature.
     *
     * @param  ProductFeature  $feature  Enum, never a bare string — see ProductFeature.
     * @param  string  $action  viewed | ran | downloaded | created | message_sent
     * @param  int|null  $customerId  Defaults to the session's active account.
     * @param  int|null  $userId  Defaults to the authenticated user.
     */
    public static function record(
        ProductFeature $feature,
        string $action = 'viewed',
        ?int $customerId = null,
        ?int $userId = null,
    ): void {
        if (! config('feature_usage.enabled', true)) {
            return;
        }

        try {
            $customerId ??= self::activeCustomerId();
            $userId ??= Auth::id();

            $now = now();

            // Wrapped so a failure here cannot poison a transaction the CALLER
            // opened. In Postgres, any failed statement aborts the whole
            // transaction — every subsequent query returns "current transaction
            // is aborted" until rollback. Catching the exception would then be
            // theatre: the request is already doomed. Nested inside an open
            // transaction Laravel issues a SAVEPOINT, so a failed upsert rolls
            // back to just before itself and the caller's work survives.
            DB::transaction(function () use ($customerId, $userId, $feature, $action, $now) {
                DB::table('feature_usage_daily')->upsert(
                    [[
                        'customer_id' => $customerId,
                        'user_id' => $userId,
                        'feature' => $feature->value,
                        'action' => $action,
                        'day' => $now->toDateString(),
                        'count' => 1,
                        'last_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]],
                    ['customer_id', 'user_id', 'feature', 'action', 'day'],
                    [
                        // Increment in SQL rather than read-modify-write: two
                        // concurrent requests from the same user would otherwise
                        // both read N and both write N+1.
                        'count' => DB::raw('feature_usage_daily.count + 1'),
                        'last_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            });
        } catch (\Throwable $e) {
            // report() reaches the admin exception dashboard; Log::error alone
            // does not (see CLAUDE.md). Both, then carry on serving the page.
            report($e);
            Log::error('Failed to record feature usage', [
                'feature' => $feature->value,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The account the user is currently working in.
     *
     * session('active_customer_id') is how the rest of the app resolves this
     * (DashboardController, CroController, SeoController). Null is a legitimate
     * answer — a user who has signed up but not yet created an account — and is
     * stored as null rather than dropped, because those are exactly the accounts
     * whose activation the dashboard is trying to measure.
     */
    private static function activeCustomerId(): ?int
    {
        $id = session('active_customer_id');

        return $id !== null ? (int) $id : null;
    }
}
