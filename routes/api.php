<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Stripe webhook endpoint (no auth middleware)
Route::post('/stripe/webhook', [\App\Http\Controllers\StripeWebhookController::class, 'handleWebhook'])
    ->name('stripe.webhook');

// Attribution tracking endpoints (public, rate-limited via controller)
Route::post('/tracking/touchpoint', [\App\Http\Controllers\TrackingController::class, 'touchpoint'])
    ->name('tracking.touchpoint');
Route::post('/tracking/conversion', [\App\Http\Controllers\TrackingController::class, 'conversion'])
    ->name('tracking.conversion');


