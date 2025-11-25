<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Stripe webhook endpoint (no auth middleware)
Route::post('/stripe/webhook', [\App\Http\Controllers\StripeWebhookController::class, 'handleWebhook'])
    ->name('stripe.webhook');

// Routes that work with session-based auth (for Inertia frontend)
Route::middleware(['auth'])->group(function () {
    Route::get('/strategies/{strategy}/collateral', [\App\Http\Controllers\CollateralController::class, 'getCollateralJson'])
        ->name('api.collateral.show');

    Route::get('/campaigns/{campaign}', [\App\Http\Controllers\CampaignController::class, 'apiShow'])
        ->name('api.campaigns.show');

    Route::get('/customers/{customer}/pages', [\App\Http\Controllers\CustomerPageController::class, 'index'])
        ->name('api.customers.pages.index');
});
