<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invitation;
use App\Models\User;
use App\Mail\UserInvitationEmail;
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

    public function accept($token)
    {
        $invitation = Invitation::where('token', $token)->firstOrFail();

        // Invitations expire after 7 days so a leaked link can't be redeemed indefinitely.
        if ($invitation->created_at && $invitation->created_at->lt(now()->subDays(7))) {
            $invitation->delete();
            abort(410, 'This invitation has expired. Please request a new one.');
        }

        $existingUser = User::where('email', $invitation->email)->first();

        if ($existingUser) {
            // An invite addressed to an existing account may only be redeemed by that
            // account while authenticated. Never log a user in from a bare token — that
            // would let anyone holding the link take over the invited account.
            if (Auth::check() && Auth::id() !== $existingUser->id) {
                abort(403, 'This invitation was sent to a different account. Please log in as ' . $invitation->email . '.');
            }

            if (!Auth::check()) {
                // Stash the accept URL as the intended destination and send to login.
                return redirect()->guest(route('login'));
            }

            $existingUser->customers()->syncWithoutDetaching([
                $invitation->customer_id => ['role' => $invitation->role],
            ]);
            $invitation->delete();

            return redirect()->route('dashboard')->with('success', 'Invitation accepted.');
        }

        // No account yet — carry the token to registration, which consumes it on signup.
        return redirect()->route('register')->with([
            'email' => $invitation->email,
            'invitation_token' => $token,
        ]);
    }
}
