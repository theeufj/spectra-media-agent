<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\FacebookController;
use App\Http\Controllers\FacebookOAuthController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Authentication Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);

    /*
    |--------------------------------------------------------------------------
    | Google OAuth Routes (Authentication)
    |--------------------------------------------------------------------------
    */
    Route::get('auth/google/redirect', [GoogleController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');

    /*
    |--------------------------------------------------------------------------
    | Facebook OAuth Routes (Authentication)
    |--------------------------------------------------------------------------
    */
    Route::get('auth/facebook/redirect', [FacebookController::class, 'redirect'])->name('facebook.redirect');
    Route::get('auth/facebook/callback', [FacebookController::class, 'callback'])->name('facebook.callback');
});

/*
|--------------------------------------------------------------------------
| Email Verification Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    // Show the email verification notice
    Route::get('/email/verify', function () {
        return Inertia::render('Auth/VerifyEmail', [
            'status' => session('status'),
        ]);
    })->name('verification.notice');
    
    // Handle the email verification link
    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();
        return redirect()->intended(route('dashboard'))->with('status', 'Your email has been verified!');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    
    // Resend verification email
    Route::post('/email/verification-notification', function (Request $request) {
        $request->user()->sendEmailVerificationNotification();
        return back()->with('status', 'A new verification link has been sent to your email address.');
    })->middleware('throttle:6,1')->name('verification.send');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
                ->name('logout');

    /*
    |--------------------------------------------------------------------------
    | Facebook Ads OAuth Routes (For connecting ad accounts)
    |--------------------------------------------------------------------------
    */
    Route::get('auth/facebook-ads/redirect', [FacebookOAuthController::class, 'redirect'])->name('facebook-ads.redirect');
    Route::get('auth/facebook-ads/callback', [FacebookOAuthController::class, 'callback'])->name('facebook-ads.callback');
    Route::post('auth/facebook-ads/disconnect', [FacebookOAuthController::class, 'disconnect'])->name('facebook-ads.disconnect');
    
    /*
    |--------------------------------------------------------------------------
    | Facebook Page Selection Routes
    |--------------------------------------------------------------------------
    */
    Route::get('facebook/pages', [FacebookOAuthController::class, 'listPages'])->name('facebook.pages.index');
    Route::post('facebook/pages/select', [FacebookOAuthController::class, 'selectPage'])->name('facebook.pages.select');
    Route::get('facebook/token-status', [FacebookOAuthController::class, 'tokenStatus'])->name('facebook.token-status');
});