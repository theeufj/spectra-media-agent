<?php

namespace App\Policies;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Model;

class StrategyPolicy extends CustomerOwnedPolicy
{
    /**
     * A strategy reaches its customer through its campaign — it has no
     * customer_id of its own.
     */
    protected function customerIdFor(Model $model): int|string|null
    {
        $campaign = $model->getAttribute('campaign');

        return $campaign instanceof Campaign ? $campaign->customer_id : null;
    }
}
