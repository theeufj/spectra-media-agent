<?php

namespace App\Listeners;

use App\Mail\WelcomeEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Mail;

/**
 * The welcome email follows verification, not registration. Sending both in
 * the same second read backwards — "Welcome!" arriving before "verify your
 * address" — and welcomed addresses nobody had proven they owned. The
 * Verified event fires exactly once, on the actual transition, so this
 * cannot double-send. OAuth signups never pass here: their provider already
 * verified the address, and their controllers welcome them directly.
 */
class SendWelcomeAfterVerification
{
    public function handle(Verified $event): void
    {
        $user = $event->user;

        Mail::to($user->getEmailForVerification())->send(
            (new WelcomeEmail($user->name ?? ''))->withTenant($user->tenant_key ?? null)
        );
    }
}
