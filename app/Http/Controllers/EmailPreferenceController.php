<?php

namespace App\Http\Controllers;

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
}
