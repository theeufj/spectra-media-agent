<?php

namespace App\Mail\Concerns;

use App\Support\Tenant;

trait HasTenantBranding
{
    protected function tenantViewData(?string $tenantKey = null): array
    {
        return Tenant::viewData($tenantKey ?? ($this->tenantKey ?? null));
    }
}
