<?php

namespace App\Notifications\Concerns;

use App\Support\Tenant;

/**
 * Tenant branding for customer-facing notifications: the salutation names
 * the skin the customer signed up under, and links point at that skin's
 * domain (which serves the same app). Resolution walks the models the
 * notification already holds — no per-send plumbing.
 */
trait TenantAware
{
    protected function tenantKey(): ?string
    {
        $models = array_filter(get_object_vars($this), 'is_object');

        return Tenant::keyFromModels(...array_values($models));
    }

    protected function tenantName(): string
    {
        return Tenant::name($this->tenantKey());
    }

    protected function tenantUrl(string $path): string
    {
        return Tenant::url($this->tenantKey(), $path);
    }

    protected function teamSalutation(): string
    {
        return '— The '.$this->tenantName().' Team';
    }
}
