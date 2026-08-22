<?php

namespace App\Services\EmailSequences;

use App\Mail\AdminNotification;
use App\Models\EmailSequenceReply;
use App\Models\EmailSequenceSend;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Captures a reply to a follow-up chain and tells the team.
 *
 * Called from the existing ResendInboundWebhookController rather than through
 * a second endpoint: Resend is already configured to post to
 * /api/resend/inbound, and a webhook the provider has never been told about is
 * a webhook that never fires.
 *
 * Replies are stored beside the email that prompted them, because a
 * conversation living only in three personal inboxes is one nobody else can
 * pick up.
 */
class SequenceReplyRecorder
{
    /**
     * @param  array<string, mixed>  $payload  the `data` object from an email.received event
     */
    public function record(array $payload): ?EmailSequenceReply
    {
        $from = $this->addressOf((string) ($payload['from'] ?? ''));

        if ($from === null) {
            return null;
        }

        // Matched on the address rather than a threading header: people reply
        // from a different client, strip headers, or forward to themselves
        // first. The most recent thing we sent them is almost always what they
        // are answering.
        $send = EmailSequenceSend::where('email', $from)
            ->whereNotNull('sent_at')
            ->latest('sent_at')
            ->first();

        // Not a reply to one of ours — the customer inbox handles those.
        if (! $send) {
            return null;
        }

        $reply = EmailSequenceReply::create([
            'email_sequence_send_id' => $send->id,
            'from_email' => $from,
            'subject' => Str::limit((string) ($payload['subject'] ?? ''), 255, ''),
            'body' => $payload['text'] ?? strip_tags((string) ($payload['html'] ?? '')),
        ]);

        $this->notifyTeam($reply);

        return $reply;
    }

    /**
     * Resolved from the admin role at send time unless pinned in config, so it
     * cannot drift out of step with who is actually running the company.
     */
    private function notifyTeam(EmailSequenceReply $reply): void
    {
        $admins = User::admins();
        $configured = config('email_sequences.reply_notify', []);

        $body = "{$reply->from_email} replied to a follow-up email.\n\n"
            ."Subject: {$reply->subject}\n\n"
            .Str::limit((string) $reply->body, 2000);

        // Addressed individually rather than one mail with everyone on it: a
        // reply from a prospect should not expose the team's addresses to
        // whoever it gets forwarded to.
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

    private function addressOf(string $from): ?string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            $from = $m[1];
        }

        $from = trim(strtolower($from));

        return filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : null;
    }
}
