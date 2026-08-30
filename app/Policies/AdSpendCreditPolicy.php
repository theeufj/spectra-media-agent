<?php

namespace App\Policies;

use App\Models\AdSpendCredit;
use App\Models\User;

class AdSpendCreditPolicy extends CustomerOwnedPolicy
{
    public function addCredit(User $user, AdSpendCredit $credit): bool
    {
        return $this->owns($user, $credit);
    }

    public function retryPayment(User $user, AdSpendCredit $credit): bool
    {
        return $this->owns($user, $credit);
    }
}
