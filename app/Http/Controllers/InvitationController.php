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

        if ($user = User::where('email', $invitation->email)->first()) {
            // User exists, attach them to the customer
            $user->customers()->attach($invitation->customer_id, ['role' => $invitation->role]);
            Auth::login($user);
        } else {
            // User does not exist, redirect to registration
            return redirect()->route('register')->with([
                'email' => $invitation->email,
                'invitation_token' => $token,
            ]);
        }

        $invitation->delete();

        return redirect()->route('dashboard');
    }
}
