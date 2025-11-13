<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('google.redirect');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

/*
|--------------------------------------------------------------------------
| Subscription Routes
|--------------------------------------------------------------------------
|
| Here we define the routes for our subscription management.
| All routes in this group are protected by the 'auth' middleware,
| ensuring only logged-in users can access them. This is similar to
| a middleware group in a Go router like Gin or Echo.
|
*/
Route::middleware(['auth'])->group(function () {
    // Route to display the pricing page.
    // GET /subscription/pricing
    Route::get('/subscription/pricing', [App\Http\Controllers\SubscriptionController::class, 'pricing'])->name('subscription.pricing');

    // Route to initiate a new subscription checkout session.
    // POST /subscription/checkout
    Route::post('/subscription/checkout', [App\Http\Controllers\SubscriptionController::class, 'checkout'])->name('subscription.checkout');

    // Route to redirect the user to the Stripe Billing Portal.
    // GET /subscription/portal
    Route::get('/subscription/portal', [App\Http\Controllers\SubscriptionController::class, 'portal'])->name('subscription.portal');
});

/*
|--------------------------------------------------------------------------
| Knowledge Base Routes
|--------------------------------------------------------------------------
|
| Routes for creating and managing the knowledge base.
|
*/
Route::middleware(['auth'])->group(function () {
    // Route to display all knowledge base entries.
    // GET /knowledge-base
    Route::get('/knowledge-base', [App\Http\Controllers\KnowledgeBaseController::class, 'index'])->name('knowledge-base.index');

    // Route to display the sitemap submission form.
    // GET /knowledge-base/create
    Route::get('/knowledge-base/create', [App\Http\Controllers\KnowledgeBaseController::class, 'create'])->name('knowledge-base.create');

    // Route to handle the form submission and dispatch the crawling job.
    // POST /knowledge-base
    Route::post('/knowledge-base', [App\Http\Controllers\KnowledgeBaseController::class, 'store'])->name('knowledge-base.store');

    // Route to delete a knowledge base entry.
    // DELETE /knowledge-base/{knowledgeBase}
    Route::delete('/knowledge-base/{knowledgeBase}', [App\Http\Controllers\KnowledgeBaseController::class, 'destroy'])->name('knowledge-base.destroy');

    // Route to search through knowledge base content.
    // POST /knowledge-base/search
    Route::post('/knowledge-base/search', [App\Http\Controllers\KnowledgeBaseController::class, 'search'])->name('knowledge-base.search');
});

/*
|--------------------------------------------------------------------------
| Campaign Routes
|--------------------------------------------------------------------------
|
| Routes for creating and managing marketing campaigns.
|
*/
Route::middleware(['auth'])->group(function () {
    // Route to display the campaign creation form.
    // GET /campaigns/create
    Route::get('/campaigns/create', [App\Http\Controllers\CampaignController::class, 'create'])->name('campaigns.create');

    // Route to handle the form submission, create the campaign, and dispatch the strategy generation job.
    // POST /campaigns
    Route::post('/campaigns', [App\Http\Controllers\CampaignController::class, 'store'])->name('campaigns.store');

    // Route to display the collateral generation page for a specific campaign strategy.
    // GET /campaigns/{campaign}/{strategy}/collateral
    Route::get('/campaigns/{campaign}/{strategy}/collateral', [App\Http\Controllers\CollateralController::class, 'show'])->name('campaigns.collateral.show');

    // Route to sign off a specific strategy.
    // POST /campaigns/{campaign}/strategies/{strategy}/sign-off
    Route::post('/campaigns/{campaign}/strategies/{strategy}/sign-off', [App\Http\Controllers\CampaignController::class, 'signOffStrategy'])->name('campaigns.strategies.sign-off');

    // Route to sign off on all strategies for a campaign.
    // POST /campaigns/{campaign}/sign-off-all
    Route::post('/campaigns/{campaign}/sign-off-all', [App\Http\Controllers\CampaignController::class, 'signOffAllStrategies'])->name('campaigns.sign-off-all');
});

/*
|--------------------------------------------------------------------------
| Ad Copy Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    // Route to generate and store ad copy for a specific campaign and strategy.
    // POST /campaigns/{campaign}/strategies/{strategy}/ad-copy
    Route::post('/campaigns/{campaign}/strategies/{strategy}/ad-copy', [App\Http\Controllers\AdCopyController::class, 'store'])->name('campaigns.ad-copy.store');

    // Route to generate and store an image for a specific campaign and strategy.
    // POST /campaigns/{campaign}/strategies/{strategy}/image
    Route::post('/campaigns/{campaign}/strategies/{strategy}/image', [App\Http\Controllers\ImageCollateralController::class, 'store'])->name('campaigns.collateral.image.store');

    // Route to refine an existing image collateral.
    // POST /image-collaterals/{image_collateral}
    Route::post('/image-collaterals/{image_collateral}', [App\Http\Controllers\ImageCollateralController::class, 'update'])->name('image-collaterals.update');
});

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'verified'])->prefix('api')->group(function () {
    Route::get('/strategies/{strategy}/collateral', [App\Http\Controllers\CollateralController::class, 'getCollateralJson'])->name('api.collateral.show');
});

/*
|--------------------------------------------------------------------------
| Knowledge Base Routes
|--------------------------------------------------------------------------
|
| Routes for creating and managing the knowledge base.
|
*/
Route::middleware(['auth'])->group(function () {
    // Route to display all knowledge base entries.
    // GET /knowledge-base
    Route::get('/knowledge-base', [App\Http\Controllers\KnowledgeBaseController::class, 'index'])->name('knowledge-base.index');

    // Route to display the sitemap submission form.
    // GET /knowledge-base/create
    Route::get('/knowledge-base/create', [App\Http\Controllers\KnowledgeBaseController::class, 'create'])->name('knowledge-base.create');

    // Route to handle the form submission and dispatch the crawling job.
    // POST /knowledge-base
    Route::post('/knowledge-base', [App\Http\Controllers\KnowledgeBaseController::class, 'store'])->name('knowledge-base.store');

    // Route to delete a knowledge base entry.
    // DELETE /knowledge-base/{knowledgeBase}
    Route::delete('/knowledge-base/{knowledgeBase}', [App\Http\Controllers\KnowledgeBaseController::class, 'destroy'])->name('knowledge-base.destroy');

    // Route to search through knowledge base content.
    // POST /knowledge-base/search
    Route::post('/knowledge-base/search', [App\Http\Controllers\KnowledgeBaseController::class, 'search'])->name('knowledge-base.search');
});

/*
|--------------------------------------------------------------------------
| Campaign Routes
|--------------------------------------------------------------------------
|
| Routes for creating and managing marketing campaigns.
|
*/
Route::middleware(['auth'])->group(function () {
    // Route to display the campaign creation form.
    // GET /campaigns/create
    Route::get('/campaigns/create', [App\Http\Controllers\CampaignController::class, 'create'])->name('campaigns.create');

    // Route to handle the form submission, create the campaign, and dispatch the strategy generation job.
    // POST /campaigns
    Route::post('/campaigns', [App\Http\Controllers\CampaignController::class, 'store'])->name('campaigns.store');

    // Route to display the collateral generation page for a specific campaign strategy.
    // GET /campaigns/{campaign}/{strategy}/collateral
    Route::get('/campaigns/{campaign}/{strategy}/collateral', [App\Http\Controllers\CollateralController::class, 'show'])->name('campaigns.collateral.show');

    // Route to sign off a specific strategy.
    // POST /campaigns/{campaign}/strategies/{strategy}/sign-off
    Route::post('/campaigns/{campaign}/strategies/{strategy}/sign-off', [App\Http\Controllers\CampaignController::class, 'signOffStrategy'])->name('campaigns.strategies.sign-off');

    // Route to sign off on all strategies for a campaign.
    // POST /campaigns/{campaign}/sign-off-all
    Route::post('/campaigns/{campaign}/sign-off-all', [App\Http\Controllers\CampaignController::class, 'signOffAllStrategies'])->name('campaigns.sign-off-all');
});

/*
|--------------------------------------------------------------------------
| Ad Copy Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    // Route to generate and store ad copy for a specific campaign and strategy.
    // POST /campaigns/{campaign}/strategies/{strategy}/ad-copy
    Route::post('/campaigns/{campaign}/strategies/{strategy}/ad-copy', [App\Http\Controllers\AdCopyController::class, 'store'])->name('campaigns.ad-copy.store');

    // Route to generate and store an image for a specific campaign and strategy.
    // POST /campaigns/{campaign}/strategies/{strategy}/image
    Route::post('/campaigns/{campaign}/strategies/{strategy}/image', [App\Http\Controllers\ImageCollateralController::class, 'store'])->name('campaigns.collateral.image.store');

    // Route to refine an existing image collateral.
    // POST /image-collaterals/{image_collateral}
    Route::post('/image-collaterals/{image_collateral}', [App\Http\Controllers\ImageCollateralController::class, 'update'])->name('image-collaterals.update');
});

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'verified'])->prefix('api')->group(function () {
    Route::get('/strategies/{strategy}/collateral', [App\Http\Controllers\CollateralController::class, 'getCollateralJson'])->name('api.collateral.show');
});
