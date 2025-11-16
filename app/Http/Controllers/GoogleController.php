<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email', 'https://www.googleapis.com/auth/adwords'])
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();
    }

    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            $user = User::updateOrCreate([
                'email' => $googleUser->getEmail(),
            ], [
                'name' => $googleUser->getName(),
                'google_id' => $googleUser->getId(),
                'password' => bcrypt(str()->random(16)) // Set a random password
            ]);

            // Create a new customer for the user
            $customer = Customer::create(['name' => $user->name]);

            // Associate the customer with the user
            $user->customer_id = $customer->id;
            $user->save();

            // Store the refresh token
            if (isset($googleUser->refreshToken)) {
                $customer->google_ads_refresh_token = Crypt::encryptString($googleUser->refreshToken);
                $customer->save();
            }

            Auth::login($user);

            return redirect('/dashboard');

        } catch (\Exception $e) {
            // Log the error
            \Log::error('Google OAuth Error: ' . $e->getMessage());
            return redirect('/login')->with('error', 'Something went wrong with the Google login.');
        }
    }
}
