<?php

namespace App\Contracts\Ads;

/**
 * Reports the ad account's own status (enabled, suspended, cancelled, closed).
 *
 * The last live API call in the health-check path. Without a seam here,
 * HealthCheckAgent could not run against sandbox data at all: its very first
 * step is a connectivity probe, so it failed before reaching any of the
 * DB-driven checks that make up the rest of it.
 */
interface AccountStatusSource
{
    /**
     * @return array{status: int, is_manager: bool, name: string}|null
     *                                                                 Status enum: 2=ENABLED, 3=CANCELED, 4=SUSPENDED, 5=CLOSED
     */
    public function __invoke(string $customerId): ?array;
}
