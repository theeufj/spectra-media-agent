<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy extends CustomerOwnedPolicy
{
    /**
     * Deploying is an ownership question like any other, but it is named
     * separately so routes can declare `can:deploy,campaign` rather than
     * borrowing `update`.
     */
    public function deploy(User $user, Campaign $campaign): bool
    {
        return $this->owns($user, $campaign);
    }
}
