<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Customer;
use App\Models\EnabledPlatform;
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
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

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
            // If the user is not coming from an invitation, create a new customer account for them.
            $customer = Customer::create();
            $user->customers()->attach($customer->id, ['role' => 'owner']);
        }

        event(new Registered($user));

        Auth::login($user);

        // Redirect to email verification page for email/password signups
        return redirect(route('verification.notice'));
    }
}
