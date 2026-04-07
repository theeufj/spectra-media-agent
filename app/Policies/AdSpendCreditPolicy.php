<?php

namespace App\Policies;

use App\Models\AdSpendCredit;
use App\Models\User;

class AdSpendCreditPolicy
{
    /**
     * Determine if the user can view the ad spend credit.
     */
    public function view(User $user, AdSpendCredit $credit): bool
    {
        return $this->userOwnsCredit($user, $credit);
    }

    /**
     * Determine if the user can add credit.
     */
    public function addCredit(User $user, AdSpendCredit $credit): bool
    {
        return $this->userOwnsCredit($user, $credit);
    }

    /**
     * Determine if the user can retry a failed payment.
     */
    public function retryPayment(User $user, AdSpendCredit $credit): bool
    {
        return $this->userOwnsCredit($user, $credit);
    }

    /**
     * Check if the user belongs to the customer that owns the credit.
     */
    protected function userOwnsCredit(User $user, AdSpendCredit $credit): bool
    {
        return $user->customers()->where('customers.id', $credit->customer_id)->exists();
    }
}
