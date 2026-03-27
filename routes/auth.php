<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\GoogleController;
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

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');

});

/*
|--------------------------------------------------------------------------
| Google OAuth Routes
|--------------------------------------------------------------------------
*/
Route::get('auth/google/redirect', [GoogleController::class, 'redirect'])->name('google.redirect');
Route::get('auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');

/*
|--------------------------------------------------------------------------
| Google Audit OAuth Routes (read-only, for free audit)
|--------------------------------------------------------------------------
*/
Route::get('auth/google/audit/redirect', [GoogleController::class, 'redirectForAudit'])->name('google.audit.redirect');
Route::get('auth/google/audit/callback', [GoogleController::class, 'callbackForAudit'])->name('google.audit.callback');

/*
|--------------------------------------------------------------------------
| Facebook OAuth Routes (accessible to both guests and authenticated users)
|--------------------------------------------------------------------------
*/
Route::get('auth/facebook/redirect', [\App\Http\Controllers\Auth\FacebookController::class, 'redirect'])->name('facebook.redirect');
Route::get('auth/facebook/callback', [\App\Http\Controllers\Auth\FacebookController::class, 'callback'])->name('facebook.callback');

/*
|--------------------------------------------------------------------------
| Facebook Audit OAuth Routes (read-only, for free audit)
|--------------------------------------------------------------------------
*/
Route::get('auth/facebook/audit/redirect', [\App\Http\Controllers\Auth\FacebookController::class, 'redirectForAudit'])->name('facebook.audit.redirect');
Route::get('auth/facebook/audit/callback', [\App\Http\Controllers\Auth\FacebookController::class, 'callbackForAudit'])->name('facebook.audit.callback');

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
        return redirect()->intended(route('customers.create'))->with('status', 'Your email has been verified! Please set up your customer profile.');
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

    Route::post('auth/facebook/disconnect', [\App\Http\Controllers\FacebookOAuthController::class, 'disconnect'])->name('facebook.disconnect');
});