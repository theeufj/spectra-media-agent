<?php

namespace App\Features;

use App\Models\Customer;

/**
 * Controls whether the SelfHealingAgent takes corrective action (rewrites ads,
 * resubmits creatives, etc.) or runs in diagnostics-only mode.
 *
 * Enabled by default for all customers. Can be disabled per-customer via admin
 * if manual review is preferred before applying fixes.
 */
class AutoHealing
{
    public function resolve(Customer $customer): bool
    {
        return true;
    }
}
