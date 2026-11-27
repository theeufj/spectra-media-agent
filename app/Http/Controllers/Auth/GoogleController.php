<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CaptureClickIds;
use App\Jobs\RecordSiteFacebookConversion;
use App\Jobs\RecordSiteMicrosoftConversion;
use App\Mail\WelcomeEmail;
use App\Models\Customer;
use App\Models\User;
use App\Services\Customers\SetupGoogleIdentity;
use App\Services\Demo\SavedAdPreview;
use App\Services\SignupFunnel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleController extends Controller
{
    public function redirect(Request $request, SavedAdPreview $previews)
    {
        session()->forget(SetupGoogleIdentity::SESSION_KEY);
        // A login link carries no signup choice. Replace any abandoned OAuth attempt.
        $request->session()->forget(['oauth_signup_measurement', 'oauth_signup_ad_preview', 'oauth_demo_url']);
        if ($request->isMethod('post')) {
            $funnel = app(SignupFunnel::class);
            $funnel->registrationSubmitted($request);
            try {
                $request->validate(['ad_measurement_consent' => 'required|boolean', 'ad_measurement_consent_version' => 'nullable|string|max:40', 'save_preview' => 'sometimes|boolean', 'preview_reference' => 'sometimes|string|size:64']);
                if ($request->boolean('save_preview')) {
                    $token = $previews->registrationToken($request);
                    if ($previews->get($request, $token) === null) {
                        throw ValidationException::withMessages([
                            'save_preview' => 'This preview is unavailable. Generate a new preview or continue without saving it.',
                        ]);
                    }
                    $request->session()->put('oauth_signup_ad_preview', $token);
                }
            } catch (ValidationException $exception) {
                $funnel->registrationFailed($request, $exception);

                throw $exception;
            }
            $request->session()->put('oauth_signup_measurement', [
                'consent' => $request->boolean('ad_measurement_consent'),
                'version' => $request->input('ad_measurement_consent_version'),
            ]);
        }
        // Preserve demo_url across the OAuth round-trip via session.
        $demoUrl = $request->input('demo_url');
        if ($demoUrl && filter_var($demoUrl, FILTER_VALIDATE_URL)) {
            session(['oauth_demo_url' => $demoUrl]);
        }

        // Only request identity scopes — Google Ads is managed via platform MCC,
        // GTM is managed via platform service account. No per-user API access needed.
        $redirect = Socialite::driver('google')->redirect();

        return $request->header('X-Inertia') ? \Inertia\Inertia::location($redirect->getTargetUrl()) : $redirect;
    }

    public function setupIdentity(Request $request, Customer $customer, SetupGoogleIdentity $identity)
    {
        $this->authorize('update', $customer);

        return $identity->start($request, $customer);
    }

    public function callback(Request $request, SavedAdPreview $previews)
    {
        // Confirming a handover identity must never sign the browser into a
        // different SiteToSpend account or create a new signup.
        if ($request->session()->has(SetupGoogleIdentity::SESSION_KEY)) {
            return app(SetupGoogleIdentity::class)->complete($request);
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException $e) {
            if ($request->session()->has('oauth_signup_measurement')) {
                app(SignupFunnel::class)->clientStage($request, 'signup_failed', $request->query('error') === 'access_denied' ? 'oauth_cancelled' : 'unavailable');
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Login session expired. Please try again.',
            ]);
        }

        $demoUrl = session()->pull('oauth_demo_url');
        $choice = $request->session()->pull('oauth_signup_measurement', []);
        $token = $request->session()->pull('oauth_signup_ad_preview');
        if (! $googleUser instanceof \Laravel\Socialite\Two\User || ! in_array($googleUser->getRaw()['email_verified'] ?? null, [true, 'true'], true)
            || ! filter_var($googleUser->getEmail(), FILTER_VALIDATE_EMAIL)) {
            if ($choice !== []) {
                app(SignupFunnel::class)->clientStage($request, 'signup_failed', 'validation');
            }

            return redirect()->route('login')->withErrors(['email' => 'Google must verify your email before you can sign in with it.']);
        }

        // Best available tenant signal: the host this callback ran on. OAuth
        // providers redirect to one registered URI, so a skin-domain signup
        // may land here on the canonical domain — QuickStart re-derives the
        // customer's tenant from the request host to close that gap.
        $tenant = request()->attributes->get('tenant', config('tenants.'.config('tenants.default')));

        $register = fn (?array $draft = null): User => User::firstOrCreate([
            'email' => $googleUser->getEmail(),
        ], [
            'name' => $googleUser->getName(),
            'password' => Hash::make(Str::random(24)),
            'demo_url' => $draft['url'] ?? $demoUrl,
            'saved_ad_preview' => $draft,
            'tenant_key' => $tenant['key'] ?? null,
            'ad_measurement_consent' => $choice['consent'] ?? null,
        ]);

        $user = is_string($token) ? $previews->claim($request, $token, $register) : null;
        if ($token && (! $user || ! $user->wasRecentlyCreated)) {
            $request->session()->flash('flash', [
                'type' => 'warning',
                'message' => $user
                    ? 'That Google account already exists. Your existing workspace and saved ads are unchanged.'
                    : 'Your preview expired before signup finished. Build a new preview whenever you are ready.',
            ]);
        }
        $user ??= $register();
        if ($token) {
            $request->session()->forget(SavedAdPreview::SESSION_KEY);
        }

        // Always mark email as verified when signing in via Google (Google has verified it)
        if (! $user->email_verified_at) {
            $user->email_verified_at = now();
            $user->save();
            if (! $user->wasRecentlyCreated) {
                app(SignupFunnel::class)->verified($request, $user);
            }
        }
        if ($user->wasRecentlyCreated) {
            app(SignupFunnel::class)->registered($request, $user);
        }

        Auth::login($user, true);

        if ($user->wasRecentlyCreated) {
            // Persist the click IDs captured on the landing page and report the
            // signup, exactly as the email/password path does. Without this,
            // anyone who chose "Continue with Google" was invisible to every ad
            // platform — no click ID stored, no conversion reported.
            $clickIds = CaptureClickIds::all();
            if (! empty($clickIds)) {
                $user->update(array_intersect_key($clickIds, array_flip(['gclid', 'gbraid', 'wbraid', 'fbclid', 'msclid'])));
                $user->refresh();
            }

            $receipt = app(\App\Services\SiteSignupConversionService::class)->record($user, $choice['version'] ?? null);
            if ($user->fbclid && $user->tenant_key === 'sitetospend' && $user->ad_measurement_consent === true) {
                RecordSiteFacebookConversion::dispatch($user, 'signup', route('register'), request()->userAgent(), $receipt->id);
            }
            if (! empty($user->msclid)) {
                RecordSiteMicrosoftConversion::dispatch($user, 'signup');
            }

            Mail::to($user->email)->send((new WelcomeEmail($user->name))->withTenant($user->tenant_key));
            Mail::raw(
                "New registration on SiteToSpend (Google OAuth)\n\nName: {$user->name}\nEmail: {$user->email}\nTime: ".now()->format('d M Y H:i T'),
                fn ($m) => $m->to(config('app.admin_email'))->subject("New signup: {$user->name}")
            );
        }

        if ($user->customers()->doesntExist()) {
            return redirect()->route('quick-start');
        }

        return redirect()->intended(route('dashboard'));
    }
}
