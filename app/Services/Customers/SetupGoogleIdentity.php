<?php

namespace App\Services\Customers;

use App\Jobs\InvitePaidSetupCustomer;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Symfony\Component\HttpFoundation\Response;

/** Verify only who will own the handover; ad-platform access stays with the MCC. */
class SetupGoogleIdentity
{
    public const SESSION_KEY = 'setup_google_identity';

    public function start(Request $request, Customer $customer): Response
    {
        abort_unless($request->user() && Gate::allows('update', $customer) && $customer->service_type === 'setup_only', 404);
        $request->session()->forget(['oauth_demo_url', self::SESSION_KEY]);
        // Staff may administer setup, but the handover identity must be
        // confirmed by someone who belongs to this customer's account.
        if (! $customer->users()->whereKey($request->user()->getKey())->exists()) {
            $request->session()->forget('state');

            return $this->failed($customer, 'Sign in as a member of this customer account to confirm the Google login that will receive ownership.');
        }
        $provider = Socialite::driver('google');
        if (! $provider instanceof GoogleProvider) {
            return $this->failed($customer, 'Google identity verification could not start. Please try again.');
        }
        $redirect = $provider->setScopes(['openid', 'email'])
            ->with(['prompt' => 'select_account consent', 'access_type' => 'online'])->redirect();
        $state = $request->session()->get('state');
        if (! is_string($state) || $state === '') {
            return $this->failed($customer, 'Google identity verification could not start. Please try again.');
        }
        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $request->user()->getKey(), 'customer_id' => $customer->getKey(),
            'expires_at' => now()->addMinutes(10)->timestamp, 'state_hash' => hash('sha256', $state),
        ]);

        return Inertia::location($redirect->getTargetUrl());
    }

    public function complete(Request $request): RedirectResponse
    {
        $intent = $request->session()->pull(self::SESSION_KEY);
        $user = $request->user();
        if (! is_array($intent) || ! $user || ($intent['user_id'] ?? null) !== $user->getKey()
            || ! is_int($intent['expires_at'] ?? null) || $intent['expires_at'] <= now()->timestamp
            || ! is_int($intent['customer_id'] ?? null)) {
            $request->session()->forget('state');

            return $this->failed(null, 'Your Google identity check expired or the signed-in account changed. Start it again from setup.');
        }
        $customer = Customer::find($intent['customer_id']);
        if (! $customer || ! Gate::forUser($user)->allows('update', $customer) || $customer->service_type !== 'setup_only') {
            $request->session()->forget('state');
            abort(404);
        }
        if (! $customer->users()->whereKey($user->getKey())->exists()) {
            $request->session()->forget('state');

            return $this->failed($customer, 'Your membership of this customer account changed. Sign in as a current member and start the Google identity check again.');
        }
        $state = $request->query('state');
        if (! is_string($state) || ! is_string($intent['state_hash'] ?? null)
            || ! hash_equals($intent['state_hash'], hash('sha256', $state))) {
            $request->session()->forget('state');

            return $this->failed($customer, 'Google identity verification could not be confirmed. Please try again.');
        }

        try {
            // Keep Socialite's stateful user() check as well as the intent
            // binding; never use stateless() or retain the returned tokens.
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $this->failed($customer, 'Google identity verification could not be confirmed. Please try again.');
        } catch (\Throwable $e) {
            report($e);

            return $this->failed($customer, 'Google could not confirm your identity. Please try again.');
        }

        if (! $googleUser instanceof GoogleUser) {
            return $this->failed($customer, 'Google identity verification could not be confirmed. Please try again.');
        }
        $raw = $googleUser->getRaw();
        $email = $googleUser->getEmail();
        $subject = $raw['sub'] ?? null;
        if (($raw['email_verified'] ?? null) !== true || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || ! is_string($subject) || $subject === '' || strlen($subject) > 255 || $subject !== $googleUser->getId()
            || ($raw['email'] ?? null) !== $email) {
            return $this->failed($customer, 'Choose a Google account with a verified email address. Your setup identity has not changed.');
        }

        DB::transaction(function () use ($customer, $user, $email, $subject): void {
            $current = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            abort_unless(Gate::forUser($user)->allows('update', $current) && $current->service_type === 'setup_only'
                && $current->users()->whereKey($user->getKey())->exists(), 404);
            $handover = $current->setup_handover ?? [];
            $previous = $handover['google_identity'] ?? [];
            $email = strtolower($email);
            if (($previous['subject'] ?? null) !== $subject || ($previous['email'] ?? null) !== $email) {
                unset($handover['ads_access'], $handover['completed_at']);
                $current->handover_at = null;
            }
            if (($previous['subject'] ?? null) !== $subject) {
                $tracking = $current->gtm_config ?? [];
                unset($tracking['ownership']);
                $current->gtm_config = $tracking;
            }
            $handover['google_identity'] = ['email' => $email, 'subject' => $subject,
                'verified_at' => now()->toIso8601String(), 'verified_by_user_id' => $user->getKey()];
            $current->setup_handover = $handover;
            $current->save();
        });
        $current = $customer->fresh();
        if ($current) {
            InvitePaidSetupCustomer::dispatchIfReady($current);
        }

        return redirect()->route('customers.gtm.setup', $customer)->with('flash', [
            'type' => 'success', 'message' => 'Google identity confirmed. Continue with account access and tracking setup.',
        ]);
    }

    private function failed(?Customer $customer, string $message): RedirectResponse
    {
        return redirect()->route($customer ? 'customers.gtm.setup' : (auth()->check() ? 'dashboard' : 'login'), $customer ? [$customer] : [])
            ->withErrors(['google_identity' => $message]);
    }
}
