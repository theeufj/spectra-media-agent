<?php

namespace App\Features;

use App\Models\User;
use Laravel\Pennant\Feature;

/**
 * Example beta feature. Agency plan users get automatic access.
 * Can also be explicitly activated for specific users via the admin panel.
 */
class BetaDashboard
{
    public function resolve(User $user): bool
    {
        return $user->hasFeature('beta_features');
    }
}
