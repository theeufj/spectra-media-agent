<?php

namespace App\Notifications;

use App\Support\Tenant;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

/**
 * The first email an email/password signup ever receives — and the stock one
 * broke onboarding for tenant skins twice over: it wore Laravel/Site to
 * Spend dress regardless of the skin the user signed up under, and its link
 * pointed at APP_URL. A Real Property Ads signup clicking it landed on
 * sitetospend.com, where they had no session, and was bounced to a login
 * page for a brand they'd never heard of.
 *
 * The link is signed RELATIVE (verification.verify runs signed:relative) so
 * the same signature is valid on whichever tenant domain we prefix — the
 * one the user actually registered on, where their session lives.
 */
class VerifyEmailAddress extends VerifyEmail
{
    protected function verificationUrl($notifiable): string
    {
        $relative = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
            absolute: false,
        );

        return rtrim(Tenant::url($notifiable->tenant_key ?? null, '/'), '/').$relative;
    }

    public function toMail($notifiable): MailMessage
    {
        $tenantKey = $notifiable->tenant_key ?? null;

        return (new MailMessage)
            ->view(
                ['html' => 'emails.notification', 'text' => 'emails.notification-text'],
                Tenant::viewData($tenantKey),
            )
            ->subject('Verify your email address')
            ->greeting('Hi '.($notifiable->name ?? 'there').',')
            ->line('Welcome to '.Tenant::name($tenantKey).'! Click the button below to verify your email address and finish setting up your account.')
            ->action('Verify Email Address', $this->verificationUrl($notifiable))
            ->line('If you didn\'t create an account, no further action is required.')
            ->salutation('— The '.Tenant::name($tenantKey).' Team');
    }
}
