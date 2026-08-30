<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Ownership for anything hanging off a Customer.
 *
 * Every policy in this directory carried its own private `userOwnsX()` — the
 * same pivot query, written out eight times, once per model. That duplication
 * is why one of them (BrandGuidelinePolicy) could sit registered and entirely
 * uninvoked without anyone noticing: there was no single place where "who owns
 * this?" was answered, so there was no single place to check it was being asked.
 *
 * Ownership is the `customers` pivot on User, never a `customers.user_id`
 * column — that column was dropped.
 */
abstract class CustomerOwnedPolicy
{
    /**
     * Admins act across tenants by design; the admin console is not a tenant.
     *
     * Returns null rather than false for everyone else, so a denial here never
     * pre-empts the specific ability check below it.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->canAccessAdmin() ? true : null;
    }

    /**
     * Listing is safe for any authenticated user: CustomerScope has already
     * removed other tenants' rows from the query.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->customers()->exists();
    }

    public function update(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    public function restore(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    /**
     * The one ownership question, asked in one place.
     */
    protected function owns(User $user, Model $model): bool
    {
        $customerId = $this->customerIdFor($model);

        if ($customerId === null) {
            return false;
        }

        return $user->customers()->where('customers.id', $customerId)->exists();
    }

    /**
     * Which customer owns this record.
     *
     * Overridable for models that reach their customer indirectly — a Strategy
     * belongs to a Campaign, not to a Customer.
     */
    protected function customerIdFor(Model $model): int|string|null
    {
        // getAttribute() rather than ->customer_id: this method is typed against
        // the base Model, which has no such property, and reaching through the
        // magic accessor is the honest way to say "whatever column this is".
        $customerId = $model->getAttribute('customer_id');

        return is_int($customerId) || is_string($customerId) ? $customerId : null;
    }
}
