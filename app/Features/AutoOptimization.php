<?php

namespace App\Features;

use App\Models\Customer;

/**
 * Controls whether the RecommendationApplier auto-applies high-confidence
 * optimization recommendations (bid adjustments, budget reallocations, etc.)
 * without requiring manual approval.
 *
 * Enabled by default for all customers. Can be disabled per-customer via admin
 * if the customer prefers to review all changes before they go live.
 */
class AutoOptimization
{
    public function resolve(Customer $customer): bool
    {
        return true;
    }
}
