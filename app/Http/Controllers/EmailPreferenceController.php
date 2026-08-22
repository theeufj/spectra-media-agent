<?php

namespace App\Http\Controllers;

use App\Models\LandingLead;
use App\Models\User;
use Illuminate\Http\Request;

class EmailPreferenceController extends Controller
{
    public function unsubscribe(Request $request, User $user)
    {
        $prefs = $user->notification_preferences ?? [];
        $prefs['performance_reports'] = false;
        $user->update(['notification_preferences' => $prefs]);

        return view('email-unsubscribed', ['user' => $user]);
    }

    /**
     * Stop follow-up sequences, for a registered user or a landing-page lead.
     *
     * Both are addressed because both receive these chains, and a lead has no
     * account to hold a preference on. Signed rather than authenticated: the
     * person clicking has already decided, and asking them to log in first to
     * stop email they did not want is the worst possible answer.
     *
     * Deliberately never reveals whether the address was on the list — a
     * signed link that says "no such lead" is an address-enumeration oracle.
     */
    public function unsubscribeFromSequences(Request $request, string $type, int $id)
    {
        if ($type === 'lead') {
            LandingLead::whereKey($id)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]);
        } elseif ($type === 'user' && $user = User::find($id)) {
            $prefs = is_array($user->notification_preferences) ? $user->notification_preferences : [];
            $prefs['sequences'] = false;
            $user->update(['notification_preferences' => $prefs]);
        }

        return view('email-unsubscribed', ['user' => $type === 'user' ? User::find($id) : null]);
    }
}
