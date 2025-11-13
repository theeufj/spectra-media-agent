<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\GoogleController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
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
});