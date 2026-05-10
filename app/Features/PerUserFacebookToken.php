<?php

namespace App\Features;

use App\Models\User;

class PerUserFacebookToken
{
    public function resolve(User $user): bool
    {
        return false; // Off by default — enable via admin after Meta App Review approval
    }
}
