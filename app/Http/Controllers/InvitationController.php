<?php

namespace App\Http\Controllers;

use App\Mail\UserInvitationEmail;
use App\Models\Customer;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    public function store(Request $request, Customer $customer)
    {
        if (Auth::user()->isOwnerOf($customer)) {
            $request->validate([
                'email' => 'required|email',
                'role' => 'required|string|in:biller,marketing',
            ]);

            $invitation = Invitation::create([
                'customer_id' => $customer->id,
                'email' => $request->email,
                'role' => $request->role,
                'token' => Str::random(32),
            ]);

            Mail::to($request->email)->send(new UserInvitationEmail($invitation));

            return redirect()->back()->with('success', 'Invitation sent.');
        }

        return redirect()->back()->with('error', 'You do not have permission to invite users to this customer.');
    }

    /**
     * Has this invitation aged out?
     *
     * Seven days, in one place. A token grants access to somebody else's
     * tenant, so an unexpiring one is a standing key to their account.
     */
    public static function hasExpired(Invitation $invitation): bool
    {
        return $invitation->created_at !== null
            && $invitation->created_at->lt(now()->subDays(7));
    }

    /**
     * Consume an invitation on behalf of a user, if it is theirs to consume.
     *
     * Two rules, applied here rather than at each call site: the invitation is
     * still inside its window, and it was addressed to this user's own email.
     * Registration used to apply neither — it looked the token up and attached
     * the pivot — so a leaked or stale link joined whoever held it to the
     * tenant, under any address, indefinitely.
     *
     * Returns false without granting anything when the invitation is not
     * redeemable. An expired one is deleted on sight so the link goes dead.
     */
    public static function redeem(Invitation $invitation, User $user): bool
    {
        if (self::hasExpired($invitation)) {
            $invitation->delete();

            return false;
        }

        if (strtolower($invitation->email) !== strtolower($user->email)) {
            return false;
        }

        $user->customers()->syncWithoutDetaching([
            $invitation->customer_id => ['role' => $invitation->role],
        ]);
        $invitation->delete();

        return true;
    }

    public function accept($token)
    {
        $invitation = Invitation::where('token', $token)->firstOrFail();

        // Checked here as well as inside redeem() so an expired link gets the
        // 410 that explains itself, rather than a bare refusal.
        if (self::hasExpired($invitation)) {
            $invitation->delete();
            abort(410, 'This invitation has expired. Please request a new one.');
        }

        $existingUser = User::where('email', $invitation->email)->first();

        if ($existingUser) {
            // An invite addressed to an existing account may only be redeemed by that
            // account while authenticated. Never log a user in from a bare token — that
            // would let anyone holding the link take over the invited account.
            if (Auth::check() && Auth::id() !== $existingUser->id) {
                abort(403, 'This invitation was sent to a different account. Please log in as '.$invitation->email.'.');
            }

            if (! Auth::check()) {
                // Stash the accept URL as the intended destination and send to login.
                return redirect()->guest(route('login'));
            }

            if (! self::redeem($invitation, $existingUser)) {
                abort(403, 'This invitation can no longer be redeemed.');
            }

            return redirect()->route('dashboard')->with('success', 'Invitation accepted.');
        }

        // No account yet — carry the token to registration, which consumes it on signup.
        return redirect()->route('register')->with([
            'email' => $invitation->email,
            'invitation_token' => $token,
        ]);
    }
}
