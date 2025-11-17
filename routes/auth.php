<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\GoogleController;
use App\Http\Controllers\FacebookOAuthController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Authentication Routes
    |--------------------------------------------------------------------------
    */
    Route::get('/login', function () {
        return Inertia::render('Auth/Login');
    })->name('login');

    Route::get('/register', function () {
        return Inertia::render('Auth/Register');
    })->name('register');

    /*
    |--------------------------------------------------------------------------
    | Google OAuth Routes
    |--------------------------------------------------------------------------
    */
    Route::get('auth/google/redirect', [GoogleController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
                ->name('logout');

    /*
    |--------------------------------------------------------------------------
    | Facebook OAuth Routes
    |--------------------------------------------------------------------------
    */
    Route::get('auth/facebook/redirect', [FacebookOAuthController::class, 'redirect'])->name('facebook.redirect');
    Route::get('auth/facebook/callback', [FacebookOAuthController::class, 'callback'])->name('facebook.callback');
    Route::post('auth/facebook/disconnect', [FacebookOAuthController::class, 'disconnect'])->name('facebook.disconnect');
});