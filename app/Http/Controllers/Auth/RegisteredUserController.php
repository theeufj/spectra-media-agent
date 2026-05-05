<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\RecordSiteFacebookConversion;
use App\Jobs\RecordSiteMicrosoftConversion;
use App\Models\User;
use App\Models\Customer;
use App\Rules\CloudflareTurnstile;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        // OAuth login providers (separate from ad platform EnabledPlatform table)
        $oauthProviders = [
            ['name' => 'Google', 'slug' => 'google'],
        ];

        return Inertia::render('Auth/Register', [
            'enabledPlatforms' => $oauthProviders,
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:' . User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ];

        // Add Turnstile validation if configured
        if (config('services.cloudflare.turnstile_secret_key')) {
            $rules['cf_turnstile_response'] = ['required', new CloudflareTurnstile];
        }

        $request->validate($rules);

        $demoUrl = $request->input('demo_url');

        $user = User::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            'demo_url'  => $demoUrl ? filter_var($demoUrl, FILTER_VALIDATE_URL) ?: null : null,
        ]);

        if ($request->invitation_token) {
            $invitation = \App\Models\Invitation::where('token', $request->invitation_token)->first();
            if ($invitation) {
                $user->customers()->attach($invitation->customer_id, ['role' => $invitation->role]);
                $invitation->delete();
            }
        } else {
            // If the user is not coming from an invitation, they will be prompted to create a customer
            // after email verification.
        }

        // Capture any ad click IDs stored by CaptureClickIds middleware
        $clickIds = \App\Http\Middleware\CaptureClickIds::all();
        if (!empty($clickIds)) {
            $user->update(array_intersect_key($clickIds, array_flip(['gclid', 'fbclid', 'msclid'])));
        }

        // Server-side conversion signals — fire for each platform the user arrived from
        if (!empty($user->fbclid)) {
            RecordSiteFacebookConversion::dispatch($user->fresh(), 'signup');
        }
        if (!empty($user->msclid)) {
            RecordSiteMicrosoftConversion::dispatch($user->fresh(), 'signup');
        }

        event(new Registered($user));

        \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\WelcomeEmail($user->name));

        Auth::login($user);

        // Redirect to email verification page for email/password signups
        return redirect(route('verification.notice'));
    }
}
