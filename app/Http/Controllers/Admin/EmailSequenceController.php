<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\SequenceEmail;
use App\Models\EmailSequence;
use App\Models\EmailSequenceReply;
use App\Models\EmailSequenceStep;
use App\Models\LandingLead;
use App\Services\EmailSequences\AudienceResolver;
use App\Services\StorageHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Editing the follow-up chains.
 *
 * Everything a non-developer would want to change lives here: who it comes
 * from, where replies go, what each email says, how long after joining it
 * arrives, and whether the chain runs at all. The alternative is copy changes
 * requiring a deploy, which means they do not happen.
 */
class EmailSequenceController extends Controller
{
    public function index(AudienceResolver $audiences): Response
    {
        $sequences = EmailSequence::with('steps')->get()->map(fn (EmailSequence $sequence) => [
            'id' => $sequence->id,
            'key' => $sequence->key,
            'label' => $sequence->label,
            'description' => $sequence->description,
            'audience' => $sequence->audience,
            'from_email' => $sequence->from_email,
            'from_name' => $sequence->from_name,
            'reply_to' => $sequence->reply_to,
            'signature' => $sequence->signature,
            'enabled' => $sequence->enabled,
            // How many people this chain would write to as things stand — the
            // number worth seeing before enabling it.
            'audience_size' => $audiences->for($sequence)->count(),
            'steps' => $sequence->steps->map(fn (EmailSequenceStep $step) => [
                'id' => $step->id,
                'position' => $step->position,
                'delay_hours' => $step->delay_hours,
                'subject' => $step->subject,
                'body' => $step->body,
                'format' => $step->format,
                'enabled' => $step->enabled,
                'sent_count' => $step->sends()->whereNotNull('sent_at')->count(),
            ]),
        ]);

        return Inertia::render('Admin/EmailSequences', [
            'sequences' => $sequences,
            // Stated on the page, because a chain that looks live in the UI
            // while the master switch is off is the most confusing state here.
            'globallyEnabled' => (bool) config('email_sequences.enabled', false),
            'leads' => [
                'total' => LandingLead::count(),
                'contactable' => LandingLead::contactable()->count(),
                'converted' => LandingLead::whereNotNull('converted_at')->count(),
                'unsubscribed' => LandingLead::whereNotNull('unsubscribed_at')->count(),
            ],
            'replies' => EmailSequenceReply::latest()->limit(25)->get()->map(fn ($reply) => [
                'id' => $reply->id,
                'from_email' => $reply->from_email,
                'subject' => $reply->subject,
                'body' => $reply->body,
                'received_at' => $reply->created_at?->toDateTimeString(),
            ]),
        ]);
    }

    public function updateSequence(Request $request, EmailSequence $sequence)
    {
        $validated = $request->validate([
            'label' => 'required|string|max:120',
            'from_email' => 'required|email|max:255',
            'from_name' => 'required|string|max:60',
            'reply_to' => 'nullable|email|max:255',
            'signature' => 'required|string|max:500',
            'enabled' => 'required|boolean',
        ]);

        $sequence->update($validated);

        return back()->with('flash', ['type' => 'success', 'message' => 'Sequence updated.']);
    }

    public function updateStep(Request $request, EmailSequenceStep $step)
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:200',
            // Rich bodies carry their own markup, so the plain-text ceiling of
            // 5,000 would reject a formatted email of two paragraphs.
            'body' => 'required|string|max:65000',
            'format' => 'required|in:plain,html',
            // Capped at 90 days: a delay measured in months is almost always a
            // typo, and the person receiving it has long since forgotten us.
            'delay_hours' => 'required|integer|min:0|max:2160',
            'enabled' => 'required|boolean',
        ]);

        $step->update($validated);

        return back()->with('flash', ['type' => 'success', 'message' => 'Email updated.']);
    }

    /**
     * The step rendered exactly as it will arrive.
     *
     * Built from the real mailable rather than a lookalike template, so the
     * branded shell, the signature spacing and the unsubscribe footer are the
     * ones that get sent. A preview assembled separately drifts from the email
     * the first time either changes, and nobody notices until a customer does.
     */
    public function previewStep(Request $request, EmailSequenceStep $step): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'nullable|string|max:200',
            'body' => 'nullable|string|max:65000',
            'format' => 'nullable|in:plain,html',
        ]);

        $step->loadMissing('sequence');

        // Preview the draft in the editor, not the last saved version. A
        // preview that lags behind what is on screen teaches people to ignore
        // it. Filled onto the model without saving — this is a GET-shaped
        // action that happens to need a body, so nothing is persisted.
        $step->fill(array_filter($validated, fn ($value) => $value !== null && $value !== ''));

        return response()->json([
            'html' => (new SequenceEmail(
                sequence: $step->sequence,
                step: $step,
                variables: self::SAMPLE_VARIABLES,
                unsubscribeUrl: $this->sampleUnsubscribeUrl(),
            ))->render(),
            'subject' => $step->subject,
            'from' => $step->sequence->from_name.' <'.$step->sequence->from_email.'>',
        ]);
    }

    /**
     * Send one step to one address, now.
     *
     * Deliberately does not write an `email_sequence_sends` row. That table is
     * the record of who has been written to, and its unique index is what
     * guarantees nobody gets the same step twice — a test send that claimed a
     * row would permanently suppress the real send to that person.
     *
     * Also deliberately ignores EMAIL_SEQUENCES_ENABLED: reviewing copy is the
     * thing you do *before* going live, so requiring the master switch would
     * make the button useless exactly when it is needed.
     */
    public function sendTest(Request $request, EmailSequenceStep $step)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $step->loadMissing('sequence');

        try {
            // sendNow, not send: SequenceEmail is ShouldQueue, so send() would
            // merely queue it behind whatever else is on the queue. A test that
            // reports success while the mail sits in Redis is a lie.
            Mail::to($validated['email'])->sendNow(new SequenceEmail(
                sequence: $step->sequence,
                step: $step,
                variables: self::SAMPLE_VARIABLES,
                unsubscribeUrl: $this->sampleUnsubscribeUrl(),
            ));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('flash', [
                'type' => 'error',
                'message' => 'Could not send: '.$e->getMessage(),
            ]);
        }

        return back()->with('flash', [
            'type' => 'success',
            'message' => 'Test sent to '.$validated['email'].'.',
        ]);
    }

    /**
     * Add an email to the end of a chain.
     *
     * Chains grow: four today, eight when there is more to say. Adding one is
     * a content decision, so it should not need a developer, a migration or a
     * deploy.
     *
     * The new step is disabled and starts a week after the last one, so adding
     * it can never send anything on its own — you write it first, then turn it
     * on.
     */
    public function storeStep(EmailSequence $sequence)
    {
        // Aggregates rather than "fetch the last row and read it": the steps()
        // relation bakes in orderBy('position'), so an appended orderByDesc()
        // loses to it and silently returns the FIRST step — which handed the
        // new email a position that already existed. max() cannot be wrong
        // about which is last, whatever the relation's ordering does.
        $position = (int) $sequence->steps()->max('position') + 1;
        $latestDelay = (int) $sequence->steps()->max('delay_hours');

        $step = $sequence->steps()->create([
            'position' => $position,
            // A week after the last one, clamped so it stays inside the
            // 90-day ceiling the update validation enforces.
            'delay_hours' => min($latestDelay + 168, 2160),
            'subject' => 'New email',
            'body' => "Hi {{ first_name }},\n\n\n\n".$sequence->from_name,
            'format' => 'plain',
            'enabled' => false,
        ]);

        return back()->with('flash', [
            'type' => 'success',
            'message' => 'Email '.$step->position.' added — disabled until you turn it on.',
        ]);
    }

    /**
     * Remove an email from a chain.
     *
     * The `email_sequence_sends` rows cascade with it, which is the intended
     * behaviour: the step no longer exists, so there is nothing left for the
     * "have we already sent this?" check to be about. Remaining steps are
     * renumbered so the chain does not read 1, 2, 4.
     */
    public function destroyStep(EmailSequenceStep $step)
    {
        $sequence = $step->sequence;
        $sent = $step->sends()->whereNotNull('sent_at')->count();

        $step->delete();

        $sequence->steps()->orderBy('position')->get()
            ->each(fn (EmailSequenceStep $remaining, int $index) => $remaining->update(['position' => $index + 1]));

        return back()->with('flash', [
            'type' => 'success',
            'message' => $sent > 0
                ? "Email deleted. It had been sent {$sent} times; that history is gone with it."
                : 'Email deleted.',
        ]);
    }

    /**
     * Store an image and hand back a URL the editor can embed.
     *
     * Has to be an absolute, publicly reachable URL: an inbox has no session
     * and no base document, so a relative path or anything behind auth renders
     * as a broken image for every recipient.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            // Bounded because these are embedded in email, where a 5MB header
            // image is a deliverability problem rather than a design choice.
            'image' => 'required|image|mimes:jpg,jpeg,png,gif,webp|max:2048',
        ]);

        $file = $request->file('image');
        $name = 'email-assets/'.Str::uuid().'.'.$file->getClientOriginalExtension();

        [, $url] = StorageHelper::put($name, (string) file_get_contents($file->getRealPath()), $file->getMimeType());

        return response()->json(['url' => $url]);
    }

    /**
     * Stand-in values so a preview or a test shows placeholders resolved,
     * rather than showing the reviewer their own variable names.
     *
     * @var array<string, string>
     */
    private const SAMPLE_VARIABLES = [
        'first_name' => 'James',
        'website' => 'https://example.com',
    ];

    /**
     * A real signed link, so the unsubscribe in a test email can be clicked and
     * proven to work. Id 0 matches no record, so clicking it cannot
     * accidentally unsubscribe a real person.
     */
    private function sampleUnsubscribeUrl(): string
    {
        return URL::signedRoute('email.sequence.unsubscribe', ['type' => 'user', 'id' => 0]);
    }
}
