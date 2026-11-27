<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\InvitationController;
use App\Jobs\RecordSiteFacebookConversion;
use App\Jobs\RecordSiteMicrosoftConversion;
use App\Models\User;
use App\Rules\CloudflareTurnstile;
use App\Services\Demo\SavedAdPreview;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(Request $request, SavedAdPreview $previews): Response|RedirectResponse
    {
        if ($request->has('preview_token')) {
            $token = $request->query('preview_token');
            $request->session()->put(SavedAdPreview::SESSION_KEY, $previews->get($request, $token) === null ? 'unavailable' : $token);

            // Keep the one-time token out of the rendered page and analytics.
            return redirect()->route('register');
        }

        if ($request->boolean('without_preview')) {
            $request->session()->forget(SavedAdPreview::SESSION_KEY);

            return redirect()->route('register');
        }

        $token = $request->session()->get(SavedAdPreview::SESSION_KEY);
        $preview = $previews->get($request, $token);

        // OAuth login providers (separate from ad platform EnabledPlatform table)
        $oauthProviders = [
            ['name' => 'Google', 'slug' => 'google'],
        ];

        return Inertia::render('Auth/Register', [
            'enabledPlatforms' => $oauthProviders,
            'previewUrl' => $preview['url'] ?? null,
            'previewReference' => $preview && is_string($token) ? $previews->reference($token) : null,
            'previewUnavailable' => $token !== null && $preview === null,
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request, SavedAdPreview $previews): RedirectResponse
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            // Carried across by InvitationController::accept(). Validated here
            // because it was read raw off the request and fed to a query: an
            // array payload is a 500, and nothing bounded its length.
            'ad_measurement_consent' => ['nullable', 'boolean'],
            'ad_measurement_consent_version' => ['nullable', 'string', 'max:40'],
            'invitation_token' => ['nullable', 'string', 'max:64'],
            'save_preview' => ['sometimes', 'boolean'],
            'preview_reference' => ['sometimes', 'string', 'size:64'],
        ];

        // Both keys, via the rule's own predicate — see CloudflareTurnstile::enabled().
        if (CloudflareTurnstile::enabled()) {
            $rules['cf_turnstile_response'] = ['required', new CloudflareTurnstile];
        }

        $request->validate($rules);

        $demoUrl = $request->input('demo_url');

        $tenant = $request->attributes->get('tenant', config('tenants.'.config('tenants.default')));

        $attributes = [
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'demo_url' => $demoUrl ? filter_var($demoUrl, FILTER_VALIDATE_URL) ?: null : null,
            'tenant_key' => $tenant['key'] ?? null,
            'ad_measurement_consent' => $request->has('ad_measurement_consent') ? $request->boolean('ad_measurement_consent') : null,
        ];

        if ($request->boolean('save_preview')) {
            $token = $previews->registrationToken($request);
            $user = $token !== null ? $previews->claim($request, $token, fn (array $draft) => User::create([
                ...$attributes, 'demo_url' => $draft['url'], 'saved_ad_preview' => $draft,
            ])) : null;
            if ($user === null) {
                throw ValidationException::withMessages([
                    'save_preview' => 'This preview is unavailable. Generate a new preview or continue without saving it.',
                ]);
            }
        } else {
            $user = User::create($attributes);
        }
        $request->session()->forget(SavedAdPreview::SESSION_KEY);

        // A lead who registers stops being a lead. Without this both chains
        // run and they get told what they are missing after they have joined.
        \App\Models\LandingLead::where('email', strtolower($user->email))
            ->whereNull('converted_at')
            ->update(['converted_user_id' => $user->id, 'converted_at' => now()]);

        $invitationToken = $request->input('invitation_token');

        if ($invitationToken) {
            // Redeemed through the same gate InvitationController::accept()
            // uses — 7-day expiry, and the address the invite was sent to.
            // This path used to skip both, so a leaked or stale token granted
            // tenant access under an arbitrary email, forever.
            $invitation = \App\Models\Invitation::where('token', $invitationToken)->first();

            if (! $invitation || ! InvitationController::redeem($invitation, $user)) {
                // Registration itself stands; they just don't join the tenant,
                // and land on the create-a-customer prompt like anyone else.
                // Logged because a refused token is the only trace of someone
                // trying a leaked link.
                \Illuminate\Support\Facades\Log::warning('Registration presented an invitation token that could not be redeemed', [
                    'user_id' => $user->id,
                    'found' => (bool) $invitation,
                ]);
            }
        }

        // Without an invitation they are prompted to create a customer after
        // email verification.

        // Capture any ad click IDs stored by CaptureClickIds middleware
        $clickIds = \App\Http\Middleware\CaptureClickIds::all();
        if (! empty($clickIds)) {
            $user->update(array_intersect_key($clickIds, array_flip(['gclid', 'gbraid', 'wbraid', 'fbclid', 'msclid'])));
        }

        // Server-side conversion signals — fire for each platform the user arrived from.
        // Google runs through the Data Manager API (the legacy
        // ConversionUploadService.UploadClickConversions endpoint is closed to new
        // integrations). This previously relied on a client-side gtag signup event
        // that no page ever fired, so Google saw none of these registrations.
        $freshUser = $user->fresh();
        $receipt = app(\App\Services\SiteSignupConversionService::class)->record($freshUser, $request->input('ad_measurement_consent_version'), formSuppliedEmail: true);
        if ($freshUser->fbclid && $freshUser->tenant_key === 'sitetospend' && $freshUser->ad_measurement_consent === true) {
            RecordSiteFacebookConversion::dispatch($freshUser, 'signup', $request->url(), $request->userAgent(), $receipt->id);
        }
        if (! empty($freshUser->msclid)) {
            RecordSiteMicrosoftConversion::dispatch($freshUser, 'signup');
        }

        event(new Registered($user));

        // No welcome email here: registration's one email is the verification
        // link. The welcome follows once they verify (SendWelcomeAfterVerification)
        // — "Welcome!" before "prove this is your address" read backwards, and
        // an unverified address may not even be theirs.

        \Illuminate\Support\Facades\Mail::raw(
            "New registration on SiteToSpend\n\nName: {$user->name}\nEmail: {$user->email}\nTime: ".now()->format('d M Y H:i T'),
            fn ($m) => $m->to(config('app.admin_email'))->subject("New signup: {$user->name}")
        );

        Auth::login($user);

        // Redirect to email verification page for email/password signups
        return redirect(route('verification.notice'));
    }
}
