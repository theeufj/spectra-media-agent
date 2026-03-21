<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    /**
     * Determine if the user can view the campaign.
     */
    public function view(User $user, Campaign $campaign): bool
    {
        return $this->userOwnsCampaign($user, $campaign);
    }

    /**
     * Determine if the user can update the campaign.
     */
    public function update(User $user, Campaign $campaign): bool
    {
        return $this->userOwnsCampaign($user, $campaign);
    }

    /**
     * Determine if the user can delete the campaign.
     */
    public function delete(User $user, Campaign $campaign): bool
    {
        return $this->userOwnsCampaign($user, $campaign);
    }

    /**
     * Determine if the user can deploy the campaign.
     */
    public function deploy(User $user, Campaign $campaign): bool
    {
        return $this->userOwnsCampaign($user, $campaign);
    }

    /**
     * Check if the user belongs to the customer that owns the campaign.
     */
    protected function userOwnsCampaign(User $user, Campaign $campaign): bool
    {
        return $user->customers()->where('customers.id', $campaign->customer_id)->exists();
    }
}
