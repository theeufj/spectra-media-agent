<?php

namespace App\Notifications\Concerns;

use App\Support\Tenant;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tenant branding for customer-facing notifications: the salutation names
 * the skin the customer signed up under, and links point at that skin's
 * domain (which serves the same app). Resolution walks the models the
 * notification already holds — no per-send plumbing.
 */
trait TenantAware
{
    /**
     * A MailMessage that renders through the branded email layout instead of
     * Laravel's stock notification template — same greeting/line/action API,
     * but the customer sees the skin they signed up under, not a grey
     * default that looks like an unconfigured system email.
     */
    protected function brandedMail(): MailMessage
    {
        $models = array_values(array_filter(get_object_vars($this), 'is_object'));
        $customer = Tenant::customerFromModels(...$models);
        $class = static::class;

        return (new MailMessage)
            ->view('emails.notification', Tenant::viewData($this->tenantKey()))
            // Stamp the customer for the per-customer email log (LogSentEmail).
            ->withSymfonyMessage(function ($message) use ($customer, $class) {
                $message->getHeaders()->addTextHeader('X-App-Mailable', $class);
                if ($customer?->id) {
                    $message->getHeaders()->addTextHeader('X-Customer-Id', (string) $customer->id);
                }
            });
    }

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
