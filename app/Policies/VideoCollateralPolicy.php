<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\Strategy;
use Illuminate\Database\Eloquent\Model;

/**
 * Video collateral reaches its customer through a campaign, or through a
 * strategy's campaign — it has no customer_id of its own, so it cannot carry
 * BelongsToCustomer and is not covered by the tenant scope. The policy is the
 * only thing standing between a URL-supplied ID and another tenant's asset.
 */
class VideoCollateralPolicy extends CustomerOwnedPolicy
{
    protected function customerIdFor(Model $model): int|string|null
    {
        $campaign = $model->getAttribute('campaign');

        if (! $campaign instanceof Campaign) {
            $strategy = $model->getAttribute('strategy');
            $campaign = $strategy instanceof Strategy ? $strategy->campaign : null;
        }

        return $campaign instanceof Campaign ? $campaign->customer_id : null;
    }
}
