<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    /**
     * Determine if the user can view the customer.
     */
    public function view(User $user, Customer $customer): bool
    {
        return $this->userOwnsCustomer($user, $customer);
    }

    /**
     * Determine if the user can update the customer.
     */
    public function update(User $user, Customer $customer): bool
    {
        return $this->userOwnsCustomer($user, $customer);
    }

    /**
     * Determine if the user can delete the customer.
     */
    public function delete(User $user, Customer $customer): bool
    {
        return $this->userOwnsCustomer($user, $customer);
    }

    /**
     * Determine if the user can switch to this customer context.
     */
    public function switchTo(User $user, Customer $customer): bool
    {
        return $this->userOwnsCustomer($user, $customer);
    }

    /**
     * Check if the user belongs to this customer via the pivot table.
     */
    protected function userOwnsCustomer(User $user, Customer $customer): bool
    {
        return $user->customers()->where('customers.id', $customer->id)->exists();
    }
}
