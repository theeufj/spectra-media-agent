<?php

namespace App\Policies;

use App\Models\BrandGuideline;
use App\Models\User;

class BrandGuidelinePolicy
{
    public function view(User $user, BrandGuideline $brandGuideline): bool
    {
        return $this->userOwnsBrandGuideline($user, $brandGuideline);
    }

    public function update(User $user, BrandGuideline $brandGuideline): bool
    {
        return $this->userOwnsBrandGuideline($user, $brandGuideline);
    }

    public function delete(User $user, BrandGuideline $brandGuideline): bool
    {
        return $this->userOwnsBrandGuideline($user, $brandGuideline);
    }

    protected function userOwnsBrandGuideline(User $user, BrandGuideline $brandGuideline): bool
    {
        return $user->customers()->where('customers.id', $brandGuideline->customer_id)->exists();
    }
}
