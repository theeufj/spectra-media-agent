<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Stripe webhook endpoint (no auth middleware)
Route::post('/stripe/webhook', [\Laravel\Cashier\Http\Controllers\WebhookController::class, 'handleWebhook'])
    ->name('cashier.webhook');

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/deployment/toggle-collateral', [\App\Http\Controllers\DeploymentController::class, 'toggleCollateral'])
        ->name('deployment.toggle-collateral');

    Route::post('/deployment/deploy', [\App\Http\Controllers\DeploymentController::class, 'deploy'])
        ->name('deployment.deploy');
    
    Route::get('/strategies/{strategy}/collateral', [\App\Http\Controllers\CollateralController::class, 'getCollateralJson'])
        ->name('api.collateral.show');
});

// Routes that work with session-based auth (for Inertia frontend)
Route::middleware(['auth'])->group(function () {
    Route::get('/campaigns/{campaign}', [\App\Http\Controllers\CampaignController::class, 'apiShow'])
        ->name('api.campaigns.show');
});
