<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require an explicit acknowledgement for actions that are hard to undo.
 *
 * A confirmation dialog in the UI is advice to the person clicking. It is not a
 * control: it does not survive a CSRF-shaped mistake, a mis-wired button, a
 * replayed request, or a script written against the endpoint. The server has to
 * be the one that refuses.
 *
 * The list is deliberately short. Guarding everything trains people to send the
 * flag reflexively, which is the same as not having it — these are the actions
 * that destroy records, move money, or change what the whole platform
 * authenticates with.
 */
class ConfirmDestructiveAction
{
    /**
     * Route names that must carry an explicit acknowledgement.
     *
     * @var list<string>
     */
    private const GUARDED = [
        'admin.users.delete',
        'admin.plans.destroy',
        'admin.mcc-accounts.destroy',
        'admin.mcc-accounts.activate',
        'admin.revenue.refund',
        'admin.health.flush-jobs',
        'admin.runtime-exceptions.flush',
        'admin.feature-flags.purge',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();

        if (! $name || ! in_array($name, self::GUARDED, true)) {
            return $next($request);
        }

        if (! $request->boolean('confirmed')) {
            // 422 rather than 403: the caller is allowed to do this, they just
            // have not said so deliberately enough.
            abort(422, 'This action is irreversible and must be confirmed explicitly.');
        }

        return $next($request);
    }
}
