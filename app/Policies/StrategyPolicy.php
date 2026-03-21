<?php

namespace App\Policies;

use App\Models\Strategy;
use App\Models\User;

class StrategyPolicy
{
    /**
     * Determine if the user can view the strategy.
     */
    public function view(User $user, Strategy $strategy): bool
    {
        return $this->userOwnsStrategy($user, $strategy);
    }

    /**
     * Determine if the user can update the strategy.
     */
    public function update(User $user, Strategy $strategy): bool
    {
        return $this->userOwnsStrategy($user, $strategy);
    }

    /**
     * Check if the user belongs to the customer that owns the strategy's campaign.
     */
    protected function userOwnsStrategy(User $user, Strategy $strategy): bool
    {
        $campaign = $strategy->campaign;

        if (!$campaign) {
            return false;
        }

        return $user->customers()->where('customers.id', $campaign->customer_id)->exists();
    }
}
