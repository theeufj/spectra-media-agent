<?php

namespace App\Features;

use App\Models\User;

class PerUserGoogleToken
{
    public function resolve(User $user): bool
    {
        return false; // Off by default — enable via admin after Google Standard Access confirmed
    }
}
