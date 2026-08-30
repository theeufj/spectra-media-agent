<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Constrain customer-owned records to the acting user's customers.
 *
 * Ownership in this codebase is the `customers` pivot on `User`, and every
 * controller was responsible for remembering to apply it. 298 web routes, 91
 * controllers, and 20 `authorize()` calls between them — with four different
 * idioms in use and one registered policy (BrandGuidelinePolicy) that nothing
 * ever invoked. This scope makes the constraint structural: a query for another
 * tenant's rows returns nothing rather than depending on the author.
 *
 * It applies when, and only when, a user is authenticated:
 *
 * - Queue jobs and scheduled commands have no authenticated user, so the
 *   nightly batch jobs that legitimately iterate every customer are unaffected.
 * - Admins are exempt, because the admin console legitimately reads across
 *   tenants. That exemption is the whole reason `admin` cannot simply be
 *   another tenant.
 * - Tests that `actingAs()` a user get the same scoping as production, so this
 *   is exercised by the suite rather than silently inert under it.
 *
 * Deliberate cross-tenant reads opt out explicitly with
 * `Model::withoutCustomerScope()`, which is greppable in review.
 *
 * A NULL `customer_id` matches nothing — SQL `IN` never matches NULL — so an
 * orphaned row is invisible to every tenant rather than visible to all of them.
 * That is the right default, but it means a model whose `customer_id` is
 * legitimately optional must not carry {@see \App\Models\Concerns\BelongsToCustomer}.
 * SupportTicket (owned by user_id) and CreativeUsage (which deliberately writes
 * customer_id NULL mid-onboarding so the quota still applies) are the two that
 * do not.
 */
class CustomerScope implements Scope
{
    public const NAME = 'customer';

    public function apply(Builder $builder, Model $model): void
    {
        $customerIds = self::visibleCustomerIds();

        if ($customerIds === null) {
            return;
        }

        $builder->whereIn($model->qualifyColumn('customer_id'), $customerIds);
    }

    /**
     * Memoised pivot lookups, keyed by user id.
     *
     * @var array<int|string, list<int>>
     */
    private static array $cache = [];

    /**
     * The customer IDs the acting user may see, or null when the scope should
     * not apply at all (no authenticated user, or an admin).
     *
     * Resolved once per user per request: this runs on every query against
     * every customer-owned model, and the pivot lookup is the same answer each
     * time.
     *
     * @return list<int>|null
     */
    public static function visibleCustomerIds(): ?array
    {
        if (! Auth::hasUser()) {
            return null;
        }

        $user = Auth::user();

        if (! $user instanceof User || $user->canAccessAdmin()) {
            return null;
        }

        return self::$cache[$user->getKey()] ??= $user->customers()
            ->pluck('customers.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Forget the memoised pivot lookups.
     *
     * Needed between tests, and after a user's customer list changes within a
     * single request — accepting an invitation or provisioning a first customer
     * would otherwise leave the user scoped to the list they had on arrival.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
