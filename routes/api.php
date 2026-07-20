<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Stripe webhook endpoint — signature-verified (STRIPE_WEBHOOK_SECRET). Cashier's
// verification lives entirely in this middleware; our custom route must attach it
// or the endpoint accepts forged events.
Route::post('/stripe/webhook', [\App\Http\Controllers\StripeWebhookController::class, 'handleWebhook'])
    ->middleware(\Laravel\Cashier\Http\Middleware\VerifyWebhookSignature::class)
    ->name('stripe.webhook');

// Resend inbound email webhook (no auth middleware, HMAC-verified internally)
Route::post('/resend/inbound', [\App\Http\Controllers\ResendInboundWebhookController::class, 'handle'])
    ->name('resend.inbound');

// Attribution tracking endpoints (public, rate-limited)
Route::middleware('throttle:60,1')->group(function () {
    Route::post('/tracking/touchpoint', [\App\Http\Controllers\TrackingController::class, 'touchpoint'])
        ->name('tracking.touchpoint');
    Route::post('/tracking/conversion', [\App\Http\Controllers\TrackingController::class, 'conversion'])
        ->name('tracking.conversion');
});



// Demo Endpoint (Rate limited)
Route::middleware('throttle:3,1')->group(function () {
    Route::post('/demo/generate-full', [\App\Http\Controllers\Api\DemoController::class, 'generateFull'])
        ->name('demo.generate-full');
});
