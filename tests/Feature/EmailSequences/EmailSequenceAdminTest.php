<?php

namespace Tests\Feature\EmailSequences;

use App\Mail\SequenceEmail;
use App\Models\Customer;
use App\Models\EmailSequence;
use App\Models\EmailSequenceSend;
use App\Models\EmailSequenceStep;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Editing the chains from the admin portal.
 *
 * The load-bearing test here is that a test send writes no `email_sequence_sends`
 * row. That table is the record of who has been written to, and its unique index
 * is the only thing guaranteeing nobody receives the same step twice — so a test
 * send that claimed a row would permanently suppress the real send to that
 * person, silently, and only to whoever the admin happened to test with.
 */
class EmailSequenceAdminTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private EmailSequence $sequence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        Mail::fake();
        Customer::unsetEventDispatcher();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        DB::table('email_sequence_sends')->delete();
        DB::table('email_sequence_steps')->delete();
        DB::table('email_sequences')->delete();

        $this->sequence = EmailSequence::create([
            'key' => 'admin-test',
            'label' => 'Admin test chain',
            'audience' => 'landing_leads',
            'from_email' => 'james@sitetospend.com',
            'from_name' => 'James',
            'signature' => "James\nCo-Founder, Sitetospend",
            'enabled' => false,
        ]);

        $this->sequence->steps()->create([
            'position' => 1,
            'delay_hours' => 24,
            'subject' => 'About {{ website }}',
            'body' => "Hi {{ first_name }},\n\nA thought.",
            'format' => 'plain',
            'enabled' => true,
        ]);
    }

    private function step(): EmailSequenceStep
    {
        return $this->sequence->steps()->orderBy('position')->firstOrFail();
    }

    // ── Test send ─────────────────────────────────────────────────────────

    public function test_test_send_does_not_record_a_send_row(): void
    {
        // The whole point: testing an email to yourself must not consume the
        // one-send-per-person claim for anybody.
        $this->actingAs($this->admin)
            ->post(route('admin.email-sequence-steps.test', $this->step()->id), ['email' => 'james@sitetospend.com'])
            ->assertRedirect();

        $this->assertSame(0, EmailSequenceSend::count());
    }

    public function test_test_send_delivers_immediately_rather_than_queueing(): void
    {
        // SequenceEmail is ShouldQueue, so send() would merely enqueue it. A
        // test that reports success while the mail sits in Redis is a lie.
        $this->actingAs($this->admin)
            ->post(route('admin.email-sequence-steps.test', $this->step()->id), ['email' => 'matt@sitetospend.com']);

        Mail::assertSent(SequenceEmail::class, fn ($mail) => $mail->hasTo('matt@sitetospend.com'));
        Mail::assertNotQueued(SequenceEmail::class);
    }

    public function test_test_send_works_while_sequences_are_globally_disabled(): void
    {
        // Reviewing copy is what you do before going live; requiring the master
        // switch would make the button useless exactly when it is needed.
        config(['email_sequences.enabled' => false]);

        $this->actingAs($this->admin)
            ->post(route('admin.email-sequence-steps.test', $this->step()->id), ['email' => 'josh@sitetospend.com'])
            ->assertRedirect();

        Mail::assertSent(SequenceEmail::class);
    }

    public function test_test_send_requires_a_valid_email(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.email-sequence-steps.test', $this->step()->id), ['email' => 'not-an-address'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_a_regular_user_cannot_send_a_test(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.email-sequence-steps.test', $this->step()->id), ['email' => 'x@example.com'])
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    // ── Preview ───────────────────────────────────────────────────────────

    public function test_preview_renders_the_real_mailable(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.email-sequence-steps.preview', $this->step()->id), []);

        $response->assertOk()->assertJsonStructure(['html', 'subject', 'from']);

        $html = $response->json('html');

        // The shell, the signature and the unsubscribe footer all come from the
        // mailable, so their presence is what proves it is not a lookalike.
        $this->assertStringContainsString('Co-Founder, Sitetospend', $html);
        $this->assertStringContainsString('Unsubscribe', $html);
        // Placeholders resolved rather than shown as their own names.
        $this->assertStringContainsString('James', $html);
        $this->assertStringNotContainsString('{{ first_name }}', $html);
    }

    public function test_preview_renders_the_unsaved_draft(): void
    {
        // A preview that lags behind the editor teaches people to ignore it.
        $response = $this->actingAs($this->admin)->postJson(
            route('admin.email-sequence-steps.preview', $this->step()->id),
            ['subject' => 'Draft subject', 'body' => 'Draft body text', 'format' => 'plain'],
        );

        $response->assertOk();
        $this->assertStringContainsString('Draft body text', $response->json('html'));
        $this->assertSame('Draft subject', $response->json('subject'));
    }

    public function test_previewing_a_draft_does_not_save_it(): void
    {
        $original = $this->step()->body;

        $this->actingAs($this->admin)->postJson(
            route('admin.email-sequence-steps.preview', $this->step()->id),
            ['body' => 'Only a preview', 'format' => 'plain'],
        )->assertOk();

        $this->assertSame($original, $this->step()->fresh()->body);
    }

    public function test_preview_of_an_html_step_is_sanitised(): void
    {
        $response = $this->actingAs($this->admin)->postJson(
            route('admin.email-sequence-steps.preview', $this->step()->id),
            ['body' => '<p>Hi</p><script>alert(1)</script>', 'format' => 'html'],
        );

        $response->assertOk();
        $this->assertStringNotContainsString('alert(1)', $response->json('html'));
    }

    // ── Growing and shrinking a chain ─────────────────────────────────────

    public function test_an_admin_can_add_an_email_to_a_chain(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.email-sequence-steps.store', $this->sequence->id))
            ->assertRedirect();

        $this->assertSame(2, $this->sequence->steps()->count());
    }

    public function test_an_added_email_is_disabled_and_scheduled_after_the_last(): void
    {
        // Adding one must never send anything on its own — you write it first,
        // then turn it on.
        $this->actingAs($this->admin)->post(route('admin.email-sequence-steps.store', $this->sequence->id));

        $added = $this->sequence->steps()->reorder('position', 'desc')->firstOrFail();

        $this->assertFalse($added->enabled);
        $this->assertSame(2, $added->position);
        $this->assertGreaterThan(24, $added->delay_hours);
    }

    public function test_adding_to_a_longer_chain_appends_rather_than_colliding(): void
    {
        // The steps() relation bakes in orderBy('position'), so an appended
        // orderByDesc() loses to it and picks the FIRST step — which handed the
        // new email position 2 in a four-email chain. Only reproducible with
        // more than one existing step, which is why this case is here.
        foreach ([2, 3, 4] as $position) {
            $this->sequence->steps()->create([
                'position' => $position,
                'delay_hours' => $position * 48,
                'subject' => "Email {$position}",
                'body' => 'Body',
                'format' => 'plain',
                'enabled' => true,
            ]);
        }

        $this->actingAs($this->admin)->post(route('admin.email-sequence-steps.store', $this->sequence->id));

        $this->assertSame(
            [1, 2, 3, 4, 5],
            $this->sequence->steps()->orderBy('position')->pluck('position')->all(),
        );

        $added = $this->sequence->steps()->reorder('position', 'desc')->firstOrFail();
        $this->assertGreaterThan(4 * 48, $added->delay_hours);
    }

    public function test_deleting_an_email_renumbers_the_rest(): void
    {
        foreach ([2, 3] as $position) {
            $this->sequence->steps()->create([
                'position' => $position,
                'delay_hours' => $position * 48,
                'subject' => "Email {$position}",
                'body' => 'Body',
                'format' => 'plain',
                'enabled' => true,
            ]);
        }

        $middle = $this->sequence->steps()->where('position', 2)->firstOrFail();

        $this->actingAs($this->admin)
            ->delete(route('admin.email-sequence-steps.destroy', $middle->id))
            ->assertRedirect();

        // A chain that reads 1, 3 is a chain somebody will mis-read.
        $this->assertSame([1, 2], $this->sequence->steps()->orderBy('position')->pluck('position')->all());
    }

    // ── Saving copy ───────────────────────────────────────────────────────

    public function test_an_html_body_is_stored_and_a_long_one_is_accepted(): void
    {
        // The plain-text ceiling of 5,000 would reject a formatted email of a
        // couple of paragraphs once the markup is counted.
        $body = '<p>'.str_repeat('Formatted copy. ', 500).'</p>';

        $this->actingAs($this->admin)->put(route('admin.email-sequence-steps.update', $this->step()->id), [
            'subject' => 'Rich subject',
            'body' => $body,
            'format' => 'html',
            'delay_hours' => 48,
            'enabled' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame('html', $this->step()->fresh()->format);
    }

    public function test_an_unknown_format_is_rejected(): void
    {
        $this->actingAs($this->admin)->put(route('admin.email-sequence-steps.update', $this->step()->id), [
            'subject' => 'Subject',
            'body' => 'Body',
            'format' => 'markdown',
            'delay_hours' => 24,
            'enabled' => true,
        ])->assertSessionHasErrors('format');
    }

    public function test_a_stored_html_body_is_sanitised_when_it_is_sent(): void
    {
        // Sanitising on render rather than on save means a body stored before
        // the sanitiser existed is still cleaned on its way out.
        $this->step()->update([
            'format' => 'html',
            'body' => '<p>Hello</p><script>alert(1)</script>',
        ]);

        $rendered = (new SequenceEmail(
            sequence: $this->sequence,
            step: $this->step()->fresh(),
            variables: ['first_name' => 'James', 'website' => 'https://example.com'],
            unsubscribeUrl: 'https://sitetospend.com/unsub',
        ))->render();

        $this->assertStringContainsString('Hello', $rendered);
        $this->assertStringNotContainsString('alert(1)', $rendered);
    }

    // ── Image upload ──────────────────────────────────────────────────────

    public function test_an_admin_can_upload_an_image_and_gets_a_url_back(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->admin)->post(route('admin.email-sequences.image'), [
            'image' => UploadedFile::fake()->image('header.png', 600, 200),
        ]);

        $response->assertOk()->assertJsonStructure(['url']);
        // Absolute, because an inbox has no base document to resolve against.
        $this->assertStringStartsWith('http', $response->json('url'));
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->post(route('admin.email-sequences.image'), [
            'image' => UploadedFile::fake()->create('payload.svg', 10, 'image/svg+xml'),
        ])->assertSessionHasErrors('image');
    }
}
