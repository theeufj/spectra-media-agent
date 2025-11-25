<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\FacebookController;
use App\Http\Controllers\FacebookOAuthController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Authentication Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');

    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');

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
});