<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Customer;
use App\Models\AuditSession;
use App\Mail\WelcomeEmail;
use App\Jobs\RunAccountAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class FacebookController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('facebook')
            ->with([
                'config_id' => config('services.facebook.config_id'),
                'scope' => null, // Explicitly remove scope parameter - config_id handles permissions
            ])
            ->redirect();
    }

    public function callback()
    {
        try {
            $facebookUser = Socialite::driver('facebook')->user();

            // Facebook might not provide an email if the user hasn't granted permission
            $email = $facebookUser->getEmail();
            if (!$email) {
                return redirect()->route('login')->with('error', 'Unable to retrieve email from Facebook. Please use a different login method.');
            }

            $user = User::firstOrCreate([
                'email' => $email,
            ], [
                'name' => $facebookUser->getName(),
                'password' => Hash::make(Str::random(24)),
            ]);

            // Always mark email as verified when signing in via Facebook (Facebook has verified it)
            if (!$user->email_verified_at) {
                $user->email_verified_at = now();
                $user->save();
            }

            // Store the Facebook OAuth token for API access
            $accessToken = $facebookUser->token;

            if ($user->wasRecentlyCreated) {
                // Store the access token in the session to be used when creating the customer
                session(['facebook_ads_access_token' => $accessToken]);

                Mail::to($user->email)->send(new WelcomeEmail($user->name));
            } else {
                // Update existing customer's access token if we got a new one
                if ($accessToken && $user->customers()->count() > 0) {
                    $customer = $user->customers()->first();
                    $customer->update([
                        'facebook_ads_access_token' => $accessToken,
                    ]);
                }
            }

            Auth::login($user, true);

            if ($user->customers()->doesntExist()) {
                return redirect()->route('customers.create');
            }

            return redirect()->route('dashboard');
        } catch (\Exception $e) {
            \Log::error('Facebook OAuth error: ' . $e->getMessage());
            return redirect()->route('login')->with('error', 'Facebook authentication failed. Please try again.');
        }
    }

    /**
     * Redirect to Facebook OAuth with read-only scopes for the free audit.
     */
    public function redirectForAudit()
    {
        return Socialite::driver('facebook')
            ->scopes(['ads_read'])
            ->with(['state' => 'audit'])
            ->redirect();
    }

    /**
     * Handle the Facebook OAuth callback for audit sessions.
     */
    public function callbackForAudit()
    {
        try {
            $facebookUser = Socialite::driver('facebook')->user();

            $token = Str::random(64);

            // Discover ad accounts via the Graph API
            $adAccountId = null;
            try {
                $response = \Illuminate\Support\Facades\Http::get('https://graph.facebook.com/v22.0/me/adaccounts', [
                    'access_token' => $facebookUser->token,
                    'fields' => 'id,name,account_status',
                    'limit' => 1,
                ]);

                $data = $response->json();
                if (!empty($data['data'])) {
                    $adAccountId = $data['data'][0]['id'];
                }
            } catch (\Exception $e) {
                Log::warning('Could not discover Facebook Ad Account during audit OAuth', [
                    'error' => $e->getMessage(),
                ]);
            }

            $auditSession = AuditSession::create([
                'token' => $token,
                'email' => $facebookUser->getEmail(),
                'platform' => 'facebook',
                'access_token_encrypted' => Crypt::encryptString($facebookUser->token),
                'facebook_ad_account_id' => $adAccountId,
                'status' => 'pending',
            ]);

            if (!$adAccountId) {
                $auditSession->update(['status' => 'failed']);
                return redirect('/free-audit')->with('error', 'No Facebook Ad Account found. Please ensure your Facebook account has an active ad account.');
            }

            RunAccountAudit::dispatch($auditSession);

            return redirect("/free-audit/{$token}");

        } catch (\Exception $e) {
            Log::error('Facebook Audit OAuth Error: ' . $e->getMessage());
            return redirect('/free-audit')->with('error', 'Something went wrong connecting your Facebook account. Please try again.');
        }
    }
}
