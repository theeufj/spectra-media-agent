<?php

namespace App\Mail;

use App\Mail\Concerns\HasTenantBranding;
use Illuminate\Mail\Mailable;

abstract class AppMailable extends Mailable
{
    use HasTenantBranding;

    public ?string $tenantKey = null;

    public function withTenant(?string $tenantKey): static
    {
        $this->tenantKey = $tenantKey;
        return $this;
    }

    public function buildViewData(): array
    {
        return array_merge(parent::buildViewData(), $this->tenantViewData($this->tenantKey));
    }
}
