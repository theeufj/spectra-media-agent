<?php

namespace App\Policies;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Model;

/**
 * A recommendation reaches its customer through its campaign.
 *
 * `recommendations` has no `customer_id`, so the model carries neither
 * BelongsToCustomer nor CustomerScope — nothing upstream of a route-model
 * binding filters it by tenant. That is why the War Room approve/reject
 * actions could clear another tenant's pending recommendations: the id came
 * straight off the URL and the update ran unchecked.
 */
class RecommendationPolicy extends CustomerOwnedPolicy
{
    protected function customerIdFor(Model $model): int|string|null
    {
        $campaignId = $model->getAttribute('campaign_id');

        if ($campaignId === null) {
            return null;
        }

        // Deliberately unscoped: ownership is answered once, by the pivot
        // query in CustomerOwnedPolicy::owns(). Reading the campaign through
        // the tenant scope would make the verdict depend on the acting guard
        // twice, and would silently return "no owner" for an admin.
        return Campaign::withoutCustomerScope()->whereKey($campaignId)->value('customer_id');
    }
}
