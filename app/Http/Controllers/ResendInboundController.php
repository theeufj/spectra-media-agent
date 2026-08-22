<?php

namespace App\Http\Controllers;

use App\Mail\AdminNotification;
use App\Models\EmailSequenceReply;
use App\Models\EmailSequenceSend;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Replies to the follow-up chains, arriving back through Resend.
 *
 * The chains are sent from a named founder and invite an answer, so a reply is
 * the point of the whole exercise. Capturing it here rather than relying on a
 * forwarding alias means the conversation is stored beside the email that
 * prompted it, and everyone who needs to know is told by the same mechanism
 * every time.
 *
 * Always answers 200 once the signature is good. A webhook that returns an
 * error gets retried, and a retried reply notification is three people reading
 * the same message twice.
 */
class ResendInboundController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->signatureIsValid($request)) {
            Log::warning('Resend inbound webhook rejected: bad signature', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        try {
            $payload = $request->input('data', []);
            $from = $this->addressOf($payload['from'] ?? '');

            if ($from === null) {
                return response()->json(['message' => 'No sender']);
            }

            $reply = EmailSequenceReply::create([
                // Matched on the address rather than a threading header, since
                // people reply from a different client, strip headers, or
                // forward to themselves first. The most recent thing we sent
                // them is almost always what they are answering.
                'email_sequence_send_id' => EmailSequenceSend::where('email', $from)
                    ->whereNotNull('sent_at')
                    ->latest('sent_at')
                    ->value('id'),
                'from_email' => $from,
                'subject' => Str::limit((string) ($payload['subject'] ?? ''), 255, ''),
                'body' => $payload['text'] ?? strip_tags((string) ($payload['html'] ?? '')),
            ]);

            $this->notifyTeam($reply);

            return response()->json(['message' => 'Recorded']);
        } catch (\Throwable $e) {
            // Recorded but not retried: the reply is already in a mailbox, and
            // a webhook retry storm would notify the team repeatedly for one
            // message.
            report($e);
            Log::error('Resend inbound webhook failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Recorded with errors']);
        }
    }

    /**
     * Tell everyone who should know.
     *
     * Resolved from the admin role at send time by default, so it cannot drift
     * out of step with who is actually running the company — the same reason
     * support tickets stopped going to a single configured address.
     */
    private function notifyTeam(EmailSequenceReply $reply): void
    {
        $admins = User::admins();
        $configured = config('email_sequences.reply_notify', []);

        $body = "{$reply->from_email} replied to a follow-up email.\n\n"
            ."Subject: {$reply->subject}\n\n"
            .Str::limit((string) $reply->body, 2000);

        // AdminNotification personalises for a recipient, so each is addressed
        // individually rather than one mail with everyone on it — a reply from
        // a prospect should not expose the whole team's addresses to anyone who
        // ends up forwarded it.
        $recipients = $configured !== []
            ? collect($configured)->map(fn ($email) => ['email' => $email, 'user' => $admins->first()])
            : $admins->map(fn (User $admin) => ['email' => $admin->email, 'user' => $admin]);

        foreach ($recipients as $recipient) {
            if (! $recipient['user']) {
                continue;
            }

            try {
                Mail::to($recipient['email'])->queue(new AdminNotification(
                    $recipient['user'],
                    "Reply from {$reply->from_email}",
                    $body,
                ));
            } catch (\Throwable $e) {
                report($e);
                Log::error('Failed to notify a team member of a sequence reply', [
                    'reply_id' => $reply->id,
                    'recipient' => $recipient['email'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $reply->update(['notified_at' => now()]);
    }

    /**
     * Verify the Svix signature Resend sends.
     *
     * This endpoint is public and creates records plus outbound email, so an
     * unsigned request must never reach the handler. hash_equals rather than
     * == because a timing-safe comparison costs nothing here.
     */
    private function signatureIsValid(Request $request): bool
    {
        $secret = config('services.resend.webhook_secret');

        if (! $secret) {
            Log::warning('Resend inbound webhook has no secret configured; rejecting.');

            return false;
        }

        $id = $request->header('svix-id');
        $timestamp = $request->header('svix-timestamp');
        $signatureHeader = $request->header('svix-signature');

        if (! $id || ! $timestamp || ! $signatureHeader) {
            return false;
        }

        // Reject anything old enough to be a replay.
        if (abs(now()->timestamp - (int) $timestamp) > 300) {
            return false;
        }

        $key = base64_decode(Str::after($secret, 'whsec_'));
        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.".$request->getContent(), $key, true));

        // The header carries a space-separated list of versioned signatures.
        foreach (explode(' ', $signatureHeader) as $candidate) {
            if (hash_equals($expected, Str::after($candidate, ','))) {
                return true;
            }
        }

        return false;
    }

    private function addressOf(string $from): ?string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            $from = $m[1];
        }

        $from = trim(strtolower($from));

        return filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : null;
    }
}
