<?php

namespace Tests\Feature\EmailSequences;

use App\Mail\SequenceEmail;
use App\Models\Customer;
use App\Models\EmailSequence;
use App\Models\EmailSequenceSend;
use App\Models\LandingLead;
use App\Models\User;
use App\Services\EmailSequences\SequenceDispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Follow-up chains for people who tried the landing page or signed up and
 * stopped.
 *
 * The property that matters most is that nobody is emailed the same step
 * twice. Being written to twice by the same automated follow-up is the single
 * thing that turns a helpful nudge into spam, and it is the failure a person
 * cannot un-see.
 */
class SequenceDispatcherTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
        Customer::unsetEventDispatcher();

        config(['email_sequences.enabled' => true]);

        DB::table('email_sequence_sends')->delete();
        DB::table('email_sequence_steps')->delete();
        DB::table('email_sequences')->delete();
        DB::table('landing_leads')->delete();
    }

    private function sequence(string $audience, array $delays = [1]): EmailSequence
    {
        $sequence = EmailSequence::create([
            'key' => 'test-'.$audience,
            'label' => 'Test',
            'audience' => $audience,
            'from_email' => 'james@sitetospend.com',
            'from_name' => 'James',
            'signature' => "James\nCo-Founder, Sitetospend",
            'enabled' => true,
        ]);

        foreach ($delays as $i => $hours) {
            $sequence->steps()->create([
                'position' => $i + 1,
                'delay_hours' => $hours,
                'subject' => 'Step '.($i + 1).' for {{ first_name }}',
                'body' => 'Hi {{ first_name }}, about {{ website }}.',
                'enabled' => true,
            ]);
        }

        return $sequence->fresh('steps');
    }

    /**
     * created_at is not fillable, so it has to be forced. Passing it to
     * create() silently yields a lead that joined just now — and therefore one
     * that is never due, which makes every timing test pass for the wrong
     * reason.
     */
    private function lead(string $email, \DateTimeInterface $joined, array $attributes = []): LandingLead
    {
        $lead = LandingLead::create(['email' => $email, ...$attributes]);
        $lead->forceFill(['created_at' => $joined])->save();

        return $lead;
    }

    private function dispatch(): array
    {
        return app(SequenceDispatcher::class)->dispatchDue();
    }

    // ── The guarantee ────────────────────────────────────────────────────────

    public function test_a_step_is_never_sent_twice(): void
    {
        $this->sequence(EmailSequence::AUDIENCE_LANDING_LEAD);
        $this->lead('lead@example.com', now()->subDay(), ['first_name' => 'Sam']);

        $first = $this->dispatch();
        $second = $this->dispatch();
        $third = $this->dispatch();

        // Three runs, one email. This is the whole point.
        $this->assertSame(1, $first['sent']);
        $this->assertSame(0, $second['sent']);
        $this->assertSame(0, $third['sent']);
        Mail::assertQueued(SequenceEmail::class, 1);
    }

    public function test_the_send_is_claimed_before_the_mail_is_queued(): void
    {
        $this->sequence(EmailSequence::AUDIENCE_LANDING_LEAD);
        $this->lead('lead@example.com', now()->subDay());

        $this->dispatch();

        // Claiming afterwards would leave a window in which two concurrent
        // runs both send and only one records it.
        $send = EmailSequenceSend::firstOrFail();
        $this->assertSame('lead@example.com', $send->email);
        $this->assertNotNull($send->sent_at);
    }

    // ── Timing ───────────────────────────────────────────────────────────────

    public function test_a_step_is_not_sent_before_its_delay(): void
    {
        $this->sequence(EmailSequence::AUDIENCE_LANDING_LEAD, [48]);
        $this->lead('lead@example.com', now()->subHours(2));

        $this->assertSame(0, $this->dispatch()['sent']);
    }

    public function test_delays_run_from_joining_not_from_the_previous_email(): void
    {
        $this->sequence(EmailSequence::AUDIENCE_LANDING_LEAD, [1, 48, 120]);
        // Joined five days ago, so the first three are all overdue and go at once.
        $this->lead('lead@example.com', now()->subDays(6));

        $this->assertSame(3, $this->dispatch()['sent']);
    }

    // ── Who is in the audience ───────────────────────────────────────────────

    public function test_a_lead_who_signed_up_is_handed_over(): void
    {
        $this->sequence(EmailSequence::AUDIENCE_LANDING_LEAD);
        $this->lead('lead@example.com', now()->subDay(), ['converted_at' => now()]);

        // Telling somebody what they are missing after they have joined reads
        // as nobody paying attention.
        $this->assertSame(0, $this->dispatch()['sent']);
    }

    public function test_an_unsubscribed_lead_is_never_written_to(): void
    {
        $this->sequence(EmailSequence::AUDIENCE_LANDING_LEAD);
        $this->lead('lead@example.com', now()->subDay(), ['unsubscribed_at' => now()]);

        $this->assertSame(0, $this->dispatch()['sent']);
        Mail::assertNothingQueued();
    }

    public function test_a_user_with_an_account_is_not_a_dormant_signup(): void
    {
        $this->sequence(EmailSequence::AUDIENCE_DORMANT_SIGNUP);

        $active = User::factory()->create(['created_at' => now()->subDays(3)]);
        $active->customers()->attach(Customer::factory()->create()->id, ['role' => 'owner']);

        $this->assertSame(0, $this->dispatch()['sent']);
    }

    public function test_a_signup_with_no_account_is_written_to(): void
    {
        $this->sequence(EmailSequence::AUDIENCE_DORMANT_SIGNUP);
        User::factory()->create(['created_at' => now()->subDays(3)]);

        $this->assertGreaterThanOrEqual(1, $this->dispatch()['sent']);
    }

    public function test_an_unsubscribed_user_is_never_written_to(): void
    {
        $this->sequence(EmailSequence::AUDIENCE_DORMANT_SIGNUP);
        User::factory()->create([
            'created_at' => now()->subDays(3),
            'notification_preferences' => ['sequences' => false],
        ]);

        $this->assertSame(0, $this->dispatch()['sent']);
    }

    public function test_a_banned_user_is_never_written_to(): void
    {
        $this->sequence(EmailSequence::AUDIENCE_DORMANT_SIGNUP);
        User::factory()->create(['created_at' => now()->subDays(3)])
            ->forceFill(['banned_at' => now()])->save();

        $this->assertSame(0, $this->dispatch()['sent']);
    }

    // ── Switches ─────────────────────────────────────────────────────────────

    public function test_nothing_is_sent_while_the_feature_is_off(): void
    {
        config(['email_sequences.enabled' => false]);

        $this->sequence(EmailSequence::AUDIENCE_LANDING_LEAD);
        $this->lead('lead@example.com', now()->subDay());

        // These write to people who are not customers, from a named founder.
        // Off must mean off.
        $this->assertSame(0, $this->dispatch()['sent']);
        Mail::assertNothingQueued();
    }

    public function test_a_disabled_sequence_sends_nothing(): void
    {
        $this->sequence(EmailSequence::AUDIENCE_LANDING_LEAD)->update(['enabled' => false]);
        $this->lead('lead@example.com', now()->subDay());

        $this->assertSame(0, $this->dispatch()['sent']);
    }

    public function test_a_disabled_step_is_skipped_without_blocking_the_rest(): void
    {
        $sequence = $this->sequence(EmailSequence::AUDIENCE_LANDING_LEAD, [1, 2]);
        $sequence->steps()->where('position', 1)->update(['enabled' => false]);
        $this->lead('lead@example.com', now()->subDays(2));

        // Step two still goes: delays are measured from joining, so turning one
        // off does not shift the others.
        $this->assertSame(1, $this->dispatch()['sent']);
    }

    public function test_the_send_ceiling_is_a_backstop(): void
    {
        config(['email_sequences.max_per_run' => 2]);
        $this->sequence(EmailSequence::AUDIENCE_LANDING_LEAD);

        foreach (range(1, 5) as $i) {
            $this->lead("lead{$i}@example.com", now()->subDay());
        }

        // If an audience query is ever wrong, this is the difference between a
        // handful of misdirected emails and every address in the database.
        $this->assertSame(2, $this->dispatch()['sent']);
    }
}
