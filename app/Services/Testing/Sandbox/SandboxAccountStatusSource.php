<?php

namespace App\Services\Testing\Sandbox;

use App\Contracts\Ads\AccountStatusSource;
use App\Models\Customer;

/**
 * Reports a healthy account for sandbox customers.
 *
 * Deliberately returns ENABLED rather than a randomised or scenario-driven
 * status: the health checker's job is to flag suspended and cancelled accounts,
 * and a sandbox that intermittently claimed suspension would train people to
 * ignore the alert. Scenarios that need an unhealthy account should say so
 * explicitly rather than have it happen by chance.
 */
class SandboxAccountStatusSource implements AccountStatusSource
{
    private const STATUS_ENABLED = 2;

    public function __construct(private Customer $customer) {}

    public function __invoke(string $customerId): ?array
    {
        return [
            'status' => self::STATUS_ENABLED,
            'is_manager' => false,
            'name' => $this->customer->name.' (sandbox)',
        ];
    }
}
