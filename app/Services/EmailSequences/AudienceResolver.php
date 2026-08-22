<?php

namespace App\Services\EmailSequences;

use App\Models\EmailSequence;
use App\Models\LandingLead;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who is currently in a sequence's audience, and when they entered it.
 *
 * The entry time matters as much as the membership: every step's delay is
 * measured from it, so "signed up three weeks ago" must not receive step one
 * today as though they had just arrived.
 */
class AudienceResolver
{
    /**
     * @return Collection<int, array{type: string, id: int, email: string, name: ?string, entered_at: \Illuminate\Support\Carbon, url: ?string}>
     */
    public function for(EmailSequence $sequence): Collection
    {
        return match ($sequence->audience) {
            EmailSequence::AUDIENCE_LANDING_LEAD => $this->landingLeads(),
            EmailSequence::AUDIENCE_DORMANT_SIGNUP => $this->dormantSignups(),
            default => collect(),
        };
    }

    /**
     * Gave the landing page a website, never registered.
     *
     * Anyone who has since registered is excluded — they are handed to the
     * dormant-signup chain instead. Being told what you are missing after you
     * have joined reads as nobody paying attention.
     */
    private function landingLeads(): Collection
    {
        return LandingLead::contactable()
            ->get()
            ->map(fn (LandingLead $lead) => [
                'type' => 'lead',
                'id' => $lead->id,
                'email' => $lead->email,
                'name' => $lead->first_name,
                'entered_at' => $lead->created_at,
                'url' => $lead->url,
            ]);
    }

    /**
     * Registered, and never got as far as creating an account.
     *
     * 23 of 39 registered users are in this state. Banned and unsubscribed
     * users are excluded; so is anyone with no email, which should be
     * impossible and is checked anyway because the cost of being wrong is an
     * exception in the middle of a send loop.
     */
    private function dormantSignups(): Collection
    {
        return User::query()
            ->whereNull('banned_at')
            ->whereNotNull('email')
            // Expressed directly rather than through whereDoesntHave: the
            // customers relation is untyped, and typing it makes Larastan
            // resolve chains across a dozen unrelated files that have never
            // been analysed.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('customer_user')
                ->whereColumn('customer_user.user_id', 'users.id'))
            ->get()
            ->reject(fn (User $user) => $this->hasUnsubscribed($user))
            ->map(fn (User $user) => [
                'type' => 'user',
                'id' => $user->id,
                'email' => $user->email,
                'name' => str($user->name)->before(' ')->toString() ?: null,
                'entered_at' => $user->created_at,
                'url' => $user->demo_url,
            ])
            ->values();
    }

    /**
     * Anything other than an explicit false means they still want to hear from
     * us — including a legacy row where the column holds something other than
     * an array, which is why this does not assume the shape.
     */
    private function hasUnsubscribed(User $user): bool
    {
        $preferences = $user->notification_preferences;

        if (! is_array($preferences)) {
            return false;
        }

        return ($preferences['sequences'] ?? true) === false;
    }
}
