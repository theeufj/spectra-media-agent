<?php

namespace App\Console\Commands;

use App\Mail\SequenceEmail;
use App\Models\EmailSequence;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Send every step of every chain to the founders, so the copy can be read as
 * it will actually arrive.
 *
 * A preview pane is not the same as an email. Subject lines truncate, the
 * From name renders differently in every client, the signature sits closer to
 * the text than it looks in an editor, and the unsubscribe link either works
 * or it does not. This sends the real mailable through the real transport to
 * real inboxes.
 *
 * Deliberately unaffected by EMAIL_SEQUENCES_ENABLED: the point is to review
 * the copy while the chains are still switched off for everyone else.
 */
class SendSequencePreview extends Command
{
    protected $signature = 'sequences:preview
                            {--to=* : Override recipients; defaults to the admin team}
                            {--sequence= : Only this sequence key}';

    protected $description = 'Send every step of the follow-up chains to the team for review';

    public function handle(): int
    {
        $recipients = $this->option('to') ?: User::admins()->pluck('email')->all();

        if ($recipients === []) {
            $this->error('No recipients. Nobody holds the admin role and --to was not given.');

            return self::FAILURE;
        }

        $sequences = EmailSequence::with('steps')
            ->when($this->option('sequence'), fn ($q, $key) => $q->where('key', $key))
            ->get();

        if ($sequences->isEmpty()) {
            $this->error('No sequences found. Run: php artisan db:seed --class=EmailSequenceSeeder');

            return self::FAILURE;
        }

        $this->info('Sending to: '.implode(', ', $recipients));
        $this->newLine();

        $sent = 0;

        foreach ($sequences as $sequence) {
            $this->line("<comment>{$sequence->label}</comment>  ({$sequence->steps->count()} steps, "
                .($sequence->enabled ? 'live' : 'disabled').')');

            foreach ($sequence->steps as $step) {
                foreach ($recipients as $recipient) {
                    // sendNow, not send: SequenceEmail is ShouldQueue, so
                    // send() would merely queue it — and a preview sitting
                    // behind a thousand crawl jobs is a preview nobody reads.
                    // A review command should either deliver or fail here.
                    Mail::to($recipient)->sendNow(new SequenceEmail(
                        sequence: $sequence,
                        step: $step,
                        // Stand-in values so the placeholders can be seen
                        // resolved rather than as their own names.
                        variables: [
                            'first_name' => 'James',
                            'website' => 'https://example.com',
                        ],
                        // A real signed link, so the unsubscribe can be clicked
                        // from the preview and proven to work.
                        unsubscribeUrl: URL::signedRoute('email.sequence.unsubscribe', ['type' => 'user', 'id' => 0]),
                    ));

                    $sent++;
                }

                $this->line(sprintf('   +%-4s %s', $step->delay_hours.'h', $step->subject));
            }

            $this->newLine();
        }

        $this->info("Delivered {$sent} emails.");
        $this->comment('Sent regardless of EMAIL_SEQUENCES_ENABLED — reviewing copy does not require going live.');

        return self::SUCCESS;
    }
}
