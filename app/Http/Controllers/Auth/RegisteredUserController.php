<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Customer;
use App\Models\AuditSession;
use App\Models\EnabledPlatform;
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
        // Only include platforms that have implemented login logic
        $supportedProviders = ['google', 'facebook'];

        return Inertia::render('Auth/Register', [
            'enabledPlatforms' => EnabledPlatform::where('is_enabled', true)
                ->whereIn('slug', $supportedProviders)
                ->orderBy('sort_order')
                ->get()
                ->map(function ($platform) {
                    return [
                        'name' => $platform->name,
                        'slug' => $platform->slug,
                    ];
                })->values()->toArray(),
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

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
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

        event(new Registered($user));

        // Track audit conversion if user came from free audit
        if ($request->has('audit_token')) {
            $auditSession = AuditSession::where('token', $request->audit_token)->first();
            if ($auditSession) {
                $auditSession->markConverted($user);
            }
        }

        Auth::login($user);

        // Redirect to email verification page for email/password signups
        return redirect(route('verification.notice'));
    }
}
