<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Connection;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $customers = $user->customers()->with('users')->get();

        $googleApiConnection = Connection::where('user_id', $user->id)
            ->where('platform', 'google_api')
            ->first();

        $facebookApiConnection = Connection::where('user_id', $user->id)
            ->where('platform', 'facebook_api')
            ->first();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail'       => $user instanceof MustVerifyEmail,
            'status'                => session('status'),
            'customers'             => $customers,
            'facebookAppId'         => config('services.facebook.client_id'),
            'googleApiConnection'   => $googleApiConnection ? [
                'connected'    => true,
                'account_name' => $googleApiConnection->account_name,
                'connected_at' => $googleApiConnection->updated_at->toISOString(),
            ] : null,
            'facebookApiConnection' => $facebookApiConnection ? [
                'connected'    => true,
                'account_name' => $facebookApiConnection->account_name,
                'connected_at' => $facebookApiConnection->updated_at->toISOString(),
                'expires_at'   => $facebookApiConnection->expires_at?->toISOString(),
            ] : null,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    private function resolveCustomer(Request $request)
    {
        $user = $request->user();
        $activeCustomerId = session('active_customer_id');

        return $user->customers()->find($activeCustomerId) ?? $user->customers()->first();
    }
}
