<?php

namespace App\Policies;

use App\Models\Proposal;
use App\Models\User;

class ProposalPolicy
{
    public function view(User $user, Proposal $proposal): bool
    {
        return $this->userOwnsProposal($user, $proposal);
    }

    public function update(User $user, Proposal $proposal): bool
    {
        return $this->userOwnsProposal($user, $proposal);
    }

    public function delete(User $user, Proposal $proposal): bool
    {
        return $this->userOwnsProposal($user, $proposal);
    }

    protected function userOwnsProposal(User $user, Proposal $proposal): bool
    {
        return $user->customers()->where('customers.id', $proposal->customer_id)->exists();
    }
}
