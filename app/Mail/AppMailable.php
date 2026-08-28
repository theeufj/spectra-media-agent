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
     * For senders whose customer isn't discoverable from the mailable's own
     * models (the mailable holds only strings, or the recipient is a lead) —
     * lets the call site file the email under the right customer anyway.
     */
    public ?\App\Models\Customer $logAsCustomer = null;

    public function logAsCustomer(?\App\Models\Customer $customer): static
    {
        $this->logAsCustomer = $customer;

        return $this;
    }

    /**
     * Stamp the customer this email is about onto the message, so the email
     * log listener can file it per customer without guessing from the
     * recipient address. The stamps are stripped again before transport
     * (LogSentEmail::handleSending) — they never reach the recipient.
     */
    public function headers(): \Illuminate\Mail\Mailables\Headers
    {
        $models = array_filter(get_object_vars($this), 'is_object');
        $customer = $this->logAsCustomer ?? Tenant::customerFromModels(...array_values($models));

        return new \Illuminate\Mail\Mailables\Headers(
            text: \App\Models\EmailLog::stampHeaders($this, $customer),
        );
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
