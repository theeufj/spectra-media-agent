<?php

namespace Database\Seeders;

use App\Models\EmailSequence;
use Illuminate\Database\Seeder;

/**
 * The starting copy for both chains. Everything here is editable in the admin
 * portal — this is a first draft to react to, not a fixture.
 *
 * Written to sound like one founder writing to one person, because that is
 * what the From address claims. Four steps over twelve days, each measured
 * from when the person entered the audience.
 */
class EmailSequenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->landingLeads();
        $this->dormantSignups();
    }

    private function landingLeads(): void
    {
        $sequence = EmailSequence::updateOrCreate(
            ['key' => 'landing-lead-followup'],
            [
                'label' => 'Landing page — tried it, never signed up',
                'description' => 'Someone put their website into the landing page and did not create an account.',
                'audience' => EmailSequence::AUDIENCE_LANDING_LEAD,
                'from_email' => 'james@sitetospend.com',
                'from_name' => 'James',
                'signature' => "James\nCo-Founder, Sitetospend",
                'enabled' => false,
            ],
        );

        $this->steps($sequence, [
            [1, 'I had a look at {{ website }}', <<<'TXT'
Hi {{ first_name }},

You put {{ website }} into our site earlier and I wanted to check you actually got something useful back.

If the ads it drafted looked off, that's worth knowing — it reads your site to work out what you sell and who you sell it to, and it doesn't always get the emphasis right first time. Tell me what it missed and I'll take a look myself.

If you'd rather just see it running properly on a real budget, reply and I'll set it up with you.
TXT],
            [48, 'The bit most people get stuck on', <<<'TXT'
Hi {{ first_name }},

The thing that stops most people going further isn't the ads — it's not being sure what happens to the budget once it's live.

So, plainly: you set a daily figure, we bill seven days of it up front, and the campaigns run in your own ad accounts. You can see every dollar and stop it whenever you like. Nothing is locked in and nothing is hidden behind an agency retainer.

If that's the part you were weighing up, I'm happy to walk through it.
TXT],
            [120, 'What it looks like after a fortnight', <<<'TXT'
Hi {{ first_name }},

A quick note on what the first two weeks actually look like, since it's the question I'd want answered.

Week one is mostly the system learning which searches and audiences respond — the numbers move around and that's expected. Week two is where budget starts shifting decisively toward whatever is converting. That's the point where it stops being a draft and starts being worth judging.

If you want to try that with {{ website }}, reply and I'll get you going.
TXT],
            [288, 'Last one from me', <<<'TXT'
Hi {{ first_name }},

I won't keep writing — this is the last one.

If the timing isn't right, that's completely fine. If there was something specific that put you off, I'd genuinely like to hear it; we're small enough that it would change what we build next.

Either way, thanks for giving it a look.
TXT],
        ]);
    }

    private function dormantSignups(): void
    {
        $sequence = EmailSequence::updateOrCreate(
            ['key' => 'dormant-signup-followup'],
            [
                'label' => 'Signed up — never created an account',
                'description' => 'Registered but has not set up an advertising account.',
                'audience' => EmailSequence::AUDIENCE_DORMANT_SIGNUP,
                'from_email' => 'james@sitetospend.com',
                'from_name' => 'James',
                'signature' => "James\nCo-Founder, Sitetospend",
                'enabled' => false,
            ],
        );

        $this->steps($sequence, [
            [1, 'Want me to set yours up?', <<<'TXT'
Hi {{ first_name }},

Thanks for signing up. You haven't set anything up yet, and the first step is smaller than it looks — you give us your website address and nothing else.

From there it reads the site, works out what you sell and who to, and builds the campaign. You see the whole thing before anything goes live or gets charged.

If you'd rather I did that bit with you, just reply with your website and I'll come back with what it produces.
TXT],
            [48, 'Nothing goes live without you saying so', <<<'TXT'
Hi {{ first_name }},

One thing worth being clear about, in case it's what's holding you up: building a campaign costs nothing and commits you to nothing.

You can put your site in, watch it write the ads and the targeting, and read the whole thing. Payment only comes up when you decide to put it live — and you confirm the budget yourself before a cent moves.

So there's not much to lose by seeing what it comes back with.
TXT],
            [120, 'Is something in the way?', <<<'TXT'
Hi {{ first_name }},

You signed up a little while ago and haven't got started, which usually means one of three things: it's not the right time, something was confusing, or it's just not for you.

All three are fine — but if it's the middle one I'd like to fix it. We're small enough that one reply genuinely changes what we work on.

What stopped you?
TXT],
            [288, 'Closing the loop', <<<'TXT'
Hi {{ first_name }},

Last note from me, then I'll leave you be.

Your account stays as it is — nothing expires and there's nothing to cancel. If you want to pick it up in a month, everything will be where you left it.

And if you'd tell me what put you off, I'd be grateful.
TXT],
        ]);
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string}>  $steps
     */
    private function steps(EmailSequence $sequence, array $steps): void
    {
        foreach ($steps as $i => [$delayHours, $subject, $body]) {
            $sequence->steps()->updateOrCreate(
                ['position' => $i + 1],
                [
                    'delay_hours' => $delayHours,
                    'subject' => $subject,
                    'body' => trim($body),
                    'enabled' => true,
                ],
            );
        }
    }
}
