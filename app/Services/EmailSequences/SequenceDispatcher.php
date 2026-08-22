<?php

namespace App\Services\EmailSequences;

use App\Mail\SequenceEmail;
use App\Models\EmailSequence;
use App\Models\EmailSequenceSend;
use App\Models\EmailSequenceStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Works out who is due which step, and sends it once.
 *
 * "Once" is the whole job. Being emailed twice by the same follow-up is the
 * single thing that turns a helpful nudge into spam, and every mechanism here
 * — the claim before the send, the unique index behind it, the unsubscribe
 * check at the last moment — exists to make that impossible rather than
 * unlikely.
 */
class SequenceDispatcher
{
    public function __construct(private readonly AudienceResolver $audiences) {}

    /**
     * Send everything currently due across every enabled sequence.
     *
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function dispatchDue(): array
    {
        $tally = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        if (! config('email_sequences.enabled', false)) {
            Log::info('Email sequences are disabled; nothing dispatched.');

            return $tally;
        }

        $budget = (int) config('email_sequences.max_per_run', 200);

        foreach (EmailSequence::enabled()->with('steps')->get() as $sequence) {
            foreach ($this->audiences->for($sequence) as $recipient) {
                foreach ($sequence->steps as $step) {
                    if ($tally['sent'] >= $budget) {
                        // A backstop, not a throttle: if an audience query is
                        // ever wrong this is the difference between a handful
                        // of misdirected emails and the whole database.
                        Log::warning('Email sequence run hit its send ceiling', ['sent' => $tally['sent']]);

                        return $tally;
                    }

                    if (! $step->enabled || ! $this->isDue($step, $recipient['entered_at'])) {
                        continue;
                    }

                    $result = $this->sendStep($sequence, $step, $recipient);
                    $tally[$result]++;
                }
            }
        }

        return $tally;
    }

    /**
     * Delays run from when the person entered the audience, not from the
     * previous step, so somebody who signed up three weeks ago does not
     * receive step one today as though they had just arrived.
     */
    private function isDue(EmailSequenceStep $step, \DateTimeInterface $enteredAt): bool
    {
        return now()->greaterThanOrEqualTo(
            \Illuminate\Support\Carbon::instance($enteredAt)->addHours($step->delay_hours)
        );
    }

    /**
     * @param  array{type: string, id: int, email: string, name: ?string, entered_at: \Illuminate\Support\Carbon, url: ?string}  $recipient
     * @return 'sent'|'skipped'|'failed'
     */
    private function sendStep(EmailSequence $sequence, EmailSequenceStep $step, array $recipient): string
    {
        // Claim the send BEFORE building or queuing the mail. The unique index
        // makes this atomic, so two concurrent runs cannot both decide they are
        // the one sending it. Claiming afterwards would leave a window in which
        // both send and only one records it.
        try {
            // Wrapped so the duplicate-key failure cannot poison a transaction
            // the caller opened. In Postgres a failed statement aborts the
            // whole transaction, so catching the exception would not be enough
            // — every query after it would fail too. Nested, this is a
            // SAVEPOINT and only the failed insert is rolled back.
            $send = DB::transaction(fn () => EmailSequenceSend::create([
                'email_sequence_step_id' => $step->id,
                'recipient_type' => $recipient['type'],
                'recipient_id' => $recipient['id'],
                'email' => $recipient['email'],
            ]));
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Already sent, or being sent right now by another run.
            return 'skipped';
        }

        try {
            Mail::to($recipient['email'])->queue(new SequenceEmail(
                sequence: $sequence,
                step: $step,
                variables: [
                    'first_name' => $recipient['name'] ?? '',
                    'website' => $recipient['url'] ?? '',
                ],
                unsubscribeUrl: $this->unsubscribeUrl($recipient),
            ));

            $send->update(['sent_at' => now()]);

            return 'sent';
        } catch (\Throwable $e) {
            report($e);
            Log::error('Failed to send sequence email', [
                'step_id' => $step->id,
                'recipient' => $recipient['email'],
                'error' => $e->getMessage(),
            ]);

            // The claim row is kept with the failure recorded rather than
            // deleted. Retrying automatically risks a duplicate for something
            // that may well have gone out before the error; a human can see it
            // in the admin portal and decide.
            $send->update(['failure' => substr($e->getMessage(), 0, 500)]);

            return 'failed';
        }
    }

    /**
     * @param  array{type: string, id: int}  $recipient
     */
    private function unsubscribeUrl(array $recipient): string
    {
        // Signed and permanent: an unsubscribe link that has expired is an
        // unsubscribe link that does not work, and the person clicking it has
        // already decided.
        return URL::signedRoute('email.sequence.unsubscribe', [
            'type' => $recipient['type'],
            'id' => $recipient['id'],
        ]);
    }
}
