<?php

namespace App\Policies;

use App\Models\Strategy;
use Illuminate\Database\Eloquent\Model;

class AdCopyPolicy extends CustomerOwnedPolicy
{
    protected function customerIdFor(Model $model): int|string|null
    {
        $strategy = $model->getAttribute('strategy');

        return $strategy instanceof Strategy ? $strategy->campaign?->customer_id : null;
    }
}
