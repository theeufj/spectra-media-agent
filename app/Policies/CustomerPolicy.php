<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CustomerPolicy extends CustomerOwnedPolicy
{
    /**
     * Determine if the user can switch to this customer context.
     */
    public function switchTo(User $user, Customer $customer): bool
    {
        return $this->owns($user, $customer);
    }

    /**
     * A Customer is its own owner — there is no customer_id column here.
     */
    protected function customerIdFor(Model $model): int|string|null
    {
        return $model->getKey();
    }
}
