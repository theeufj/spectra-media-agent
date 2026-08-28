<?php

namespace App\Listeners;

use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;

/**
 * Files every outbound email into the per-customer email log. The customer
 * comes from the headers AppMailable / TenantAware stamp at build time
 * (see EmailLog::stampHeaders); emails without one (password resets,
 * invitations) are kept too and matched by recipient address on the admin
 * profile.
 *
 * The stamps are internal, so they are read and STRIPPED before transport
 * (MessageSending) — the recipient never sees a customer id or a PHP class
 * name in their raw message — and the row is only written once the
 * transport has accepted the message (MessageSent).
 *
 * Logging must never take the email down with it — a failure here only
 * loses the record.
 */
class LogSentEmail
{
    /**
     * Stamps captured at strip time. Matched back up in handleSent by a
     * subject+recipients fingerprint, NOT by message instance: real
     * transports clone the message before sending (AbstractTransport), so
     * the MessageSent event carries a different object than MessageSending
     * did. Sends are sequential within a process; the fingerprint guards
     * the one gap (a transport failure leaving a stale entry behind).
     *
     * @var list<array{fingerprint: string, customer_id: ?int, mailable: ?string}>
     */
    private static array $pending = [];

    public function handleSending(MessageSending $event): void
    {
        try {
            $headers = $event->message->getHeaders();

            $stamps = [
                'fingerprint' => self::fingerprint($event->message),
                'customer_id' => self::intOrNull($headers->getHeaderBody(EmailLog::HEADER_CUSTOMER)),
                'mailable' => self::stringOrNull($headers->getHeaderBody(EmailLog::HEADER_MAILABLE)),
            ];

            $headers->remove(EmailLog::HEADER_CUSTOMER);
            $headers->remove(EmailLog::HEADER_MAILABLE);

            // Bounded so entries orphaned by transport failures cannot grow
            // without limit in a long-lived queue worker.
            self::$pending = array_slice([...self::$pending, $stamps], -10);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function handleSent(MessageSent $event): void
    {
        try {
            $message = $event->message;

            $stamps = ['customer_id' => null, 'mailable' => null];
            $fingerprint = self::fingerprint($message);

            for ($i = count(self::$pending) - 1; $i >= 0; $i--) {
                if (self::$pending[$i]['fingerprint'] === $fingerprint) {
                    $stamps = self::$pending[$i];
                    array_splice(self::$pending, $i, 1);
                    break;
                }
            }

            foreach ($message->getTo() as $address) {
                EmailLog::create([
                    'customer_id' => $stamps['customer_id'],
                    'to_email' => $address->getAddress(),
                    'subject' => $message->getSubject(),
                    'mailable' => $stamps['mailable'],
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function fingerprint(\Symfony\Component\Mime\Email $message): string
    {
        $to = array_map(fn ($a) => $a->getAddress(), $message->getTo());
        sort($to);

        return ($message->getSubject() ?? '').'|'.implode(',', $to);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
