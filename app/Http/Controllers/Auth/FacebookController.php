<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Customer;
use App\Mail\WelcomeEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
                $customer = Customer::create([
                    'facebook_ads_access_token' => $accessToken,
                ]);
                $user->customers()->attach($customer->id, ['role' => 'owner']);

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

            return redirect()->route('dashboard');
        } catch (\Exception $e) {
            \Log::error('Facebook OAuth error: ' . $e->getMessage());
            return redirect()->route('login')->with('error', 'Facebook authentication failed. Please try again.');
        }
    }
}
