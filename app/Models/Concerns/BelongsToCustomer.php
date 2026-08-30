<?php

namespace App\Models\Concerns;

use App\Models\Customer;
use App\Models\Scopes\CustomerScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as owned by a Customer, and scopes it to the acting user.
 *
 * Applying this trait is what makes a model tenant-scoped — see
 * {@see CustomerScope} for when the scope engages and why admins and queue
 * workers are exempt. Models carrying it are also covered by the authorization
 * backstop test, which fails when a route-model-bound controller action can be
 * reached without an ownership check.
 */
trait BelongsToCustomer
{
    public static function bootBelongsToCustomer(): void
    {
        static::addGlobalScope(new CustomerScope);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Query without tenant scoping.
     *
     * For the deliberate cross-tenant read — admin reporting, reconciliation,
     * a job that has an authenticated user for an unrelated reason. Explicit
     * and greppable, which is the point.
     */
    public static function withoutCustomerScope(): Builder
    {
        return static::withoutGlobalScope(CustomerScope::class);
    }

    /**
     * Restrict to one customer regardless of who is acting.
     */
    public function scopeForCustomer(Builder $query, Customer|int|string|null $customer): Builder
    {
        return $query->where(
            $this->qualifyColumn('customer_id'),
            $customer instanceof Customer ? $customer->getKey() : $customer,
        );
    }

    /**
     * Restrict to everything a given user owns, independent of the auth guard.
     *
     * Use in jobs and commands, where there is no acting user for the global
     * scope to read.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn(
            $this->qualifyColumn('customer_id'),
            $user->customers()->select('customers.id'),
        );
    }

    /**
     * Is this record owned by one of the user's customers?
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->customer_id !== null
            && $user->customers()->where('customers.id', $this->customer_id)->exists();
    }
}
