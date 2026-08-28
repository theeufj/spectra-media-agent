<?php

namespace App\Listeners;

use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSent;

/**
 * Files every outbound email into the per-customer email log. The customer
 * comes from the X-Customer-Id header that AppMailable / TenantAware stamp
 * at build time; emails without one (password resets, invitations) are kept
 * too and matched to a customer by recipient address on the admin profile.
 *
 * Logging must never take the email down with it — the message has already
 * been sent by the time this runs, so a failure here only loses the record.
 */
class LogSentEmail
{
    public function handle(MessageSent $event): void
    {
        try {
            $headers = $event->message->getHeaders();
            $customerId = $headers->getHeaderBody('X-Customer-Id');
            $mailable = $headers->getHeaderBody('X-App-Mailable');

            foreach ($event->message->getTo() as $address) {
                EmailLog::create([
                    'customer_id' => is_numeric($customerId) ? (int) $customerId : null,
                    'to_email' => $address->getAddress(),
                    'subject' => $event->message->getSubject(),
                    'mailable' => is_string($mailable) && $mailable !== '' ? $mailable : null,
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
