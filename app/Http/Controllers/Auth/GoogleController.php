<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeEmail;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleController extends Controller
{
    public function redirect()
    {
        // Only request identity scopes — Google Ads is managed via platform MCC,
        // GTM is managed via platform service account. No per-user API access needed.
        return Socialite::driver('google')->redirect();
    }

    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException $e) {
            return redirect()->route('login')->withErrors([
                'email' => 'Login session expired. Please try again.',
            ]);
        }

        $user = User::firstOrCreate([
            'email' => $googleUser->getEmail(),
        ], [
            'name' => $googleUser->getName(),
            'password' => Hash::make(Str::random(24)),
        ]);

        // Always mark email as verified when signing in via Google (Google has verified it)
        if (!$user->email_verified_at) {
            $user->email_verified_at = now();
            $user->save();
        }

        Auth::login($user, true);

        if ($user->wasRecentlyCreated) {
            Mail::to($user->email)->send(new WelcomeEmail($user->name));
        }

        if ($user->customers()->doesntExist()) {
            return redirect()->route('customers.create');
        }

        return redirect()->intended(route('dashboard'));
    }
}
