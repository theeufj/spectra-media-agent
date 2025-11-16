<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/deployment/toggle-collateral', [\App\Http\Controllers\DeploymentController::class, 'toggleCollateral'])
        ->name('deployment.toggle-collateral');

    Route::post('/deployment/deploy', [\App\Http\Controllers\DeploymentController::class, 'deploy'])
        ->name('deployment.deploy');
});
