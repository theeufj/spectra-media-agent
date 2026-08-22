<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailSequence;
use App\Models\EmailSequenceReply;
use App\Models\EmailSequenceStep;
use App\Models\LandingLead;
use App\Services\EmailSequences\AudienceResolver;
use Illuminate\Http\Request;
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
            'body' => 'required|string|max:5000',
            // Capped at 90 days: a delay measured in months is almost always a
            // typo, and the person receiving it has long since forgotten us.
            'delay_hours' => 'required|integer|min:0|max:2160',
            'enabled' => 'required|boolean',
        ]);

        $step->update($validated);

        return back()->with('flash', ['type' => 'success', 'message' => 'Email updated.']);
    }
}
