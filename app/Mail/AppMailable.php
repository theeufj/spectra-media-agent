<?php

namespace App\Mail;

use App\Mail\Concerns\HasTenantBranding;
use App\Support\Tenant;
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
        return array_merge(
            parent::buildViewData(),
            $this->tenantViewData($this->tenantKey ?? $this->resolveTenantKey()),
        );
    }

    /**
     * Stamp the customer this email is about onto the message, so the
     * MessageSent listener can file it in the per-customer email log
     * without guessing from the recipient address.
     */
    public function headers(): \Illuminate\Mail\Mailables\Headers
    {
        $models = array_filter(get_object_vars($this), 'is_object');
        $customer = Tenant::customerFromModels(...array_values($models));

        return new \Illuminate\Mail\Mailables\Headers(text: array_filter([
            'X-App-Mailable' => static::class,
            'X-Customer-Id' => $customer?->id ? (string) $customer->id : null,
        ]));
    }

    /**
     * Most mailables never call withTenant() — resolve the skin from the
     * models they already carry (customer first, then campaign, then user),
     * so every subclass is tenant-branded without per-send-site plumbing.
     */
    protected function resolveTenantKey(): ?string
    {
        $models = array_filter(get_object_vars($this), 'is_object');

        return Tenant::keyFromModels(...array_values($models));
    }
}
