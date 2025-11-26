<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LegalController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [LandingController::class, 'index'])->name('landing');

Route::get('/terms-of-service', [LegalController::class, 'terms'])->name('terms');
Route::get('/privacy-policy', [LegalController::class, 'privacy'])->name('privacy');

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified', 'ensureUserHasCustomer'])->name('dashboard');

Route::middleware(['auth', 'ensureUserHasCustomer'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::delete('/profile/connections/{connection}', [ProfileController::class, 'disconnectAccount'])->name('profile.disconnect');
    Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'page'])->name('notifications.index');
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
    Route::post('/knowledge-base', [App\Http\Controllers\KnowledgeBaseController::class, 'store'])
        ->middleware(['throttle:3,1', 'verified'])
        ->name('knowledge-base.store');

    // Route to delete a knowledge base entry.
    // DELETE /knowledge-base/{knowledgeBase}
    Route::delete('/knowledge-base/{knowledgeBase}', [App\Http\Controllers\KnowledgeBaseController::class, 'destroy'])->name('knowledge-base.destroy');

    // Route to search through knowledge base content.
    // POST /knowledge-base/search
    Route::post('/knowledge-base/search', [App\Http\Controllers\KnowledgeBaseController::class, 'search'])->name('knowledge-base.search');
});

/*
|--------------------------------------------------------------------------
| Brand Guidelines Routes
|--------------------------------------------------------------------------
|
| Routes for viewing and managing AI-extracted brand guidelines.
|
*/
Route::middleware(['auth'])->group(function () {
    // Route to display brand guidelines.
    // GET /brand-guidelines
    Route::get('/brand-guidelines', [App\Http\Controllers\BrandGuidelineController::class, 'index'])->name('brand-guidelines.index');

    // Route to update brand guidelines.
    // PUT /brand-guidelines/{brandGuideline}
    Route::put('/brand-guidelines/{brandGuideline}', [App\Http\Controllers\BrandGuidelineController::class, 'update'])->name('brand-guidelines.update');

    // Route to verify brand guidelines as accurate.
    // POST /brand-guidelines/{brandGuideline}/verify
    Route::post('/brand-guidelines/{brandGuideline}/verify', [App\Http\Controllers\BrandGuidelineController::class, 'verify'])->name('brand-guidelines.verify');

    // Route to re-extract brand guidelines from knowledge base.
    // POST /brand-guidelines/re-extract
    Route::post('/brand-guidelines/re-extract', [App\Http\Controllers\BrandGuidelineController::class, 'reExtract'])->name('brand-guidelines.re-extract');

    // Route to export brand guidelines as PDF.
    // GET /brand-guidelines/export-pdf
    Route::get('/brand-guidelines/export-pdf', [App\Http\Controllers\BrandGuidelineController::class, 'exportPdf'])->name('brand-guidelines.export-pdf');
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

    // Route to display the new campaign creation wizard.
    // GET /campaigns/wizard
    Route::get('/campaigns/wizard', [App\Http\Controllers\CampaignController::class, 'wizard'])->name('campaigns.wizard');
    
    // Route for AI-assisted campaign creation chat.
    // POST /api/campaigns/ai-assist
    Route::post('/api/campaigns/ai-assist', [App\Http\Controllers\CampaignController::class, 'aiAssist'])->name('campaigns.ai-assist');

    // Route to handle the form submission, create the campaign, and dispatch the strategy generation job.
    // POST /campaigns
    Route::post('/campaigns', [App\Http\Controllers\CampaignController::class, 'store'])->name('campaigns.store');

    // Route to display the strategies for a specific campaign.
    // GET /campaigns/{campaign}/strategies
    Route::get('/campaigns/{campaign}/strategies', [App\Http\Controllers\CampaignController::class, 'show'])->name('campaigns.show');

    // Route to display deployment status for a campaign.
    // GET /campaigns/{campaign}/deployment-status
    Route::get('/campaigns/{campaign}/deployment-status', [App\Http\Controllers\CampaignController::class, 'deploymentStatus'])->name('campaigns.deployment-status');

    // Route to display the collateral generation page for a specific campaign strategy.
    // GET /campaigns/{campaign}/{strategy}/collateral
    Route::get('/campaigns/{campaign}/{strategy}/collateral', [App\Http\Controllers\CollateralController::class, 'show'])->name('campaigns.collateral.show');

    // Route to sign off a specific strategy.
    // POST /campaigns/{campaign}/strategies/{strategy}/sign-off
    Route::post('/campaigns/{campaign}/strategies/{strategy}/sign-off', [App\Http\Controllers\CampaignController::class, 'signOffStrategy'])->name('campaigns.strategies.sign-off');

    // Route to sign off on all strategies for a campaign.
    // POST /campaigns/{campaign}/sign-off-all
    Route::post('/campaigns/{campaign}/sign-off-all', [App\Http\Controllers\CampaignController::class, 'signOffAllStrategies'])->name('campaigns.sign-off-all');

    // Route to delete a campaign.
    // DELETE /campaigns/{campaign}
    Route::delete('/campaigns/{campaign}', [\App\Http\Controllers\CampaignController::class, 'destroy'])->name('campaigns.destroy');

    // Deployment routes
    Route::middleware(['subscribed'])->group(function () {
        Route::post('/deployment/toggle-collateral', [\App\Http\Controllers\DeploymentController::class, 'toggleCollateral'])
            ->name('deployment.toggle-collateral');
        Route::post('/deployment/deploy', [\App\Http\Controllers\DeploymentController::class, 'deploy'])
            ->name('deployment.deploy');
    });
});

/*
|--------------------------------------------------------------------------
| Ad Spend Billing Routes
|--------------------------------------------------------------------------
|
| Routes for managing ad spend credits and billing.
|
*/
Route::middleware(['auth'])->group(function () {
    // Dashboard view for ad spend billing
    Route::get('/billing/ad-spend', [App\Http\Controllers\AdSpendBillingController::class, 'index'])->name('billing.ad-spend');
    
    // API endpoints
    Route::get('/billing/ad-spend/balance', [App\Http\Controllers\AdSpendBillingController::class, 'getBalance'])->name('billing.ad-spend.balance');
    Route::get('/billing/ad-spend/transactions', [App\Http\Controllers\AdSpendBillingController::class, 'getTransactions'])->name('billing.ad-spend.transactions');
    Route::post('/billing/ad-spend/add-credit', [App\Http\Controllers\AdSpendBillingController::class, 'addCredit'])->name('billing.ad-spend.add-credit');
    Route::post('/billing/ad-spend/retry-payment', [App\Http\Controllers\AdSpendBillingController::class, 'retryPayment'])->name('billing.ad-spend.retry');
    Route::post('/billing/ad-spend/update-payment-method', [App\Http\Controllers\AdSpendBillingController::class, 'updatePaymentMethod'])->name('billing.ad-spend.update-payment-method');
    Route::post('/billing/ad-spend/setup-for-deployment', [App\Http\Controllers\AdSpendBillingController::class, 'setupForDeployment'])->name('billing.ad-spend.setup-for-deployment');
});

/*
|--------------------------------------------------------------------------
| Admin Platform Management Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('platforms', App\Http\Controllers\Admin\EnabledPlatformController::class);
    Route::post('/platforms/{platform}/toggle', [App\Http\Controllers\Admin\EnabledPlatformController::class, 'toggle'])->name('platforms.toggle');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/customers/switch/{customer}', [App\Http\Controllers\CustomerController::class, 'switch'])->name('customers.switch');
    Route::get('/customers/create', [App\Http\Controllers\CustomerController::class, 'create'])->name('customers.create');
    Route::post('/customers', [App\Http\Controllers\CustomerController::class, 'store'])->name('customers.store');
    Route::get('/customers/{customer}/edit', [App\Http\Controllers\CustomerController::class, 'edit'])->name('customers.edit');
    Route::put('/customers/{customer}', [App\Http\Controllers\CustomerController::class, 'update'])->name('customers.update');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/customers/{customer}/invitations', [App\Http\Controllers\InvitationController::class, 'store'])->name('invitations.store');
});

Route::get('/invitations/accept/{token}', [App\Http\Controllers\InvitationController::class, 'accept'])->name('invitations.accept');

/*
|--------------------------------------------------------------------------
| Google Tag Manager (GTM) Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    // Route to show GTM setup page with automatic Path A/B routing
    // GET /customers/{customer}/gtm/setup
    Route::get('/customers/{customer}/gtm/setup', [App\Http\Controllers\GTMSetupController::class, 'show'])->name('customers.gtm.setup');

    // Route to link an existing GTM container (Path A)
    // POST /customers/{customer}/gtm/link
    Route::post('/customers/{customer}/gtm/link', [App\Http\Controllers\GTMSetupController::class, 'linkExistingContainer'])->name('customers.gtm.link');

    // Route to create a new GTM container (Path B - placeholder)
    // POST /customers/{customer}/gtm/create
    Route::post('/customers/{customer}/gtm/create', [App\Http\Controllers\GTMSetupController::class, 'createNewContainer'])->name('customers.gtm.create');

    // Route to verify GTM container access
    // POST /customers/{customer}/gtm/verify
    Route::post('/customers/{customer}/gtm/verify', [App\Http\Controllers\GTMSetupController::class, 'verifyAccess'])->name('customers.gtm.verify');

    // Route to get current GTM status
    // GET /customers/{customer}/gtm/status
    Route::get('/customers/{customer}/gtm/status', [App\Http\Controllers\GTMSetupController::class, 'getStatus'])->name('customers.gtm.status');

    // Route to re-scan website for GTM detection
    // POST /customers/{customer}/gtm/rescan
    Route::post('/customers/{customer}/gtm/rescan', [App\Http\Controllers\GTMSetupController::class, 'rescan'])->name('customers.gtm.rescan');
});

/*
|--------------------------------------------------------------------------
| Ad Copy Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    // Route to generate and store ad copy for a specific campaign and strategy.
    // POST /campaigns/{campaign}/strategies/{strategy}/ad-copy
    Route::post('/campaigns/{campaign}/strategies/{strategy}/ad-copy', [App\Http\Controllers\AdCopyController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('campaigns.ad-copy.store');

    // Route to generate and store an image for a specific campaign and strategy.
    // POST /campaigns/{campaign}/strategies/{strategy}/image
    Route::post('/campaigns/{campaign}/strategies/{strategy}/image', [App\Http\Controllers\ImageCollateralController::class, 'store'])
        ->middleware(['throttle:10,1', 'verified'])
        ->name('campaigns.collateral.image.store');

    // Route to refine an existing image collateral.
    // POST /image-collaterals/{image_collateral}
    Route::post('/image-collaterals/{image_collateral}', [App\Http\Controllers\ImageCollateralController::class, 'update'])->name('image-collaterals.update');

    // Route to generate and store a video for a specific campaign and strategy.
    // POST /campaigns/{campaign}/strategies/{strategy}/video
    Route::post('/campaigns/{campaign}/strategies/{strategy}/video', [App\Http\Controllers\VideoCollateralController::class, 'store'])->name('campaigns.collateral.video.store');
    
    // Route to extend an existing Veo-generated video by up to 7 seconds
    // POST /video-collaterals/{video}/extend
    Route::post('/video-collaterals/{video}/extend', [App\Http\Controllers\VideoCollateralController::class, 'extend'])->name('video-collaterals.extend');
});

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'verified'])->prefix('api')->group(function () {
    Route::get('/strategies/{strategy}/collateral', [App\Http\Controllers\CollateralController::class, 'getCollateralJson'])->name('api.collateral.show');
    Route::get('/campaigns/{campaign}/performance', [App\Http\Controllers\CampaignController::class, 'performance'])->name('api.campaigns.performance');
});

/*
|--------------------------------------------------------------------------
| Internal API Routes (Session Auth)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->prefix('api')->group(function () {
    Route::get('/strategies/{strategy}/collateral', [\App\Http\Controllers\CollateralController::class, 'getCollateralJson'])
        ->name('api.collateral.show');

    Route::get('/campaigns/{campaign}', [\App\Http\Controllers\CampaignController::class, 'apiShow'])
        ->name('api.campaigns.show');

    Route::get('/customers/{customer}/pages', [\App\Http\Controllers\CustomerPageController::class, 'index'])
        ->name('api.customers.pages.index');

    // Notification routes
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])
        ->name('api.notifications.index');
    Route::post('/notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead'])
        ->name('api.notifications.read');
    Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead'])
        ->name('api.notifications.read-all');
    Route::delete('/notifications/{notification}', [\App\Http\Controllers\NotificationController::class, 'destroy'])
        ->name('api.notifications.destroy');
    Route::get('/notifications/preferences', [\App\Http\Controllers\NotificationController::class, 'preferences'])
        ->name('api.notifications.preferences');
    Route::post('/notifications/preferences', [\App\Http\Controllers\NotificationController::class, 'updatePreferences'])
        ->name('api.notifications.preferences.update');

    // Setup progress routes
    Route::get('/setup-progress', [\App\Http\Controllers\SetupProgressController::class, 'index'])
        ->name('api.setup-progress.index');
    Route::post('/setup-progress/{step}/skip', [\App\Http\Controllers\SetupProgressController::class, 'skipStep'])
        ->name('api.setup-progress.skip');

    // Deployment status polling
    Route::get('/campaigns/{campaign}/deployment-status', [\App\Http\Controllers\CampaignController::class, 'apiDeploymentStatus'])
        ->name('api.campaigns.deployment-status');
});

Route::get('/test-email', function () {
    Log::info('Attempting to send a test email...');
    Log::info('Mail driver:', ['driver' => config('mail.default')]);
    try {
        Mail::to('theeufj@gmail.com')->send(new \App\Mail\WelcomeEmail('Josh'));
        Log::info('Email sent successfully!');
        return 'Email sent!';
    } catch (Exception $e) {
        Log::error('Failed to send email:', ['error' => $e->getMessage()]);
        return 'Failed to send email. Check logs for details.';
    }
});

Route::get('/test-campaign-deployed', function () {
    $user = \App\Models\User::first();
    $campaign = \App\Models\Campaign::first();

    if (!$user || !$campaign) {
        return 'Please create a user and a campaign first.';
    }

    Mail::to($user->email)->send(new \App\Mail\CampaignDeployed($user, $campaign));
    return 'Campaign deployed email sent!';
});

Route::get('/test-videos-generated', function () {
    $user = \App\Models\User::first();
    $campaign = \App\Models\Campaign::first();

    if (!$user || !$campaign) {
        return 'Please create a user and a campaign first.';
    }

    Mail::to($user->email)->send(new \App\Mail\VideosGenerated($user, $campaign));
    return 'Videos generated email sent!';
});

Route::get('/test-invoice-created', function () {
    $user = \App\Models\User::first();

    if (!$user) {
        return 'Please create a user first.';
    }

    Mail::to($user->email)->send(new \App\Mail\InvoiceCreated($user, '29.99', now()->toFormattedDateString()));
    return 'Invoice created email sent!';
});

Route::get('/test-admin-notification', function () {
    $user = \App\Models\User::first();
    if (!$user) {
        return 'Please create a user first.';
    }

    $subject = 'Important Update Regarding Your Account';
    $body = '<p>We are writing to inform you about an upcoming change to our service that will affect your account. Please log in to your dashboard to review the details.</p><p>Thank you for your understanding.</p>';

    Mail::to($user->email)->send(new \App\Mail\AdminNotification($user, $subject, $body));
    return 'Admin notification sent!';
});

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    Route::get('dashboard', [App\Http\Controllers\AdminController::class, 'index'])->name('admin.dashboard');
    Route::get('users', [App\Http\Controllers\AdminController::class, 'usersIndex'])->name('admin.users.index');
    Route::get('customers', [App\Http\Controllers\AdminController::class, 'customersIndex'])->name('admin.customers.index');
    Route::get('customers/{customer}', [App\Http\Controllers\AdminController::class, 'customerShow'])->name('admin.customers.show');
    Route::get('customers/{customer}/dashboard', [App\Http\Controllers\AdminController::class, 'customerDashboard'])->name('admin.customers.dashboard');
    Route::get('campaigns/{campaign}', [App\Http\Controllers\AdminController::class, 'campaignShow'])->name('admin.campaigns.show');
    Route::get('campaigns/{campaign}/performance', [App\Http\Controllers\AdminController::class, 'campaignPerformance'])->name('admin.campaigns.performance');
    Route::post('campaigns/{campaign}/pause', [App\Http\Controllers\AdminController::class, 'pauseCampaign'])->name('admin.campaigns.pause');
    Route::post('campaigns/{campaign}/start', [App\Http\Controllers\AdminController::class, 'startCampaign'])->name('admin.campaigns.start');
    Route::put('campaigns/{campaign}', [App\Http\Controllers\AdminController::class, 'updateCampaign'])->name('admin.campaigns.update');
    Route::get('notifications', [App\Http\Controllers\AdminController::class, 'notificationsIndex'])->name('admin.notifications.index');
    Route::get('settings', [App\Http\Controllers\AdminController::class, 'settingsIndex'])->name('admin.settings.index');
    Route::post('settings', [App\Http\Controllers\AdminController::class, 'updateSettings'])->name('admin.settings.update');
    Route::post('users/{user}/promote', [App\Http\Controllers\AdminController::class, 'promoteToAdmin'])->name('admin.users.promote');
    Route::delete('customers/{customer}', [App\Http\Controllers\AdminController::class, 'deleteCustomer'])->name('admin.customers.delete');
    Route::post('users/{user}/ban', [App\Http\Controllers\AdminController::class, 'banUser'])->name('admin.users.ban');
    
    // Execution Metrics Dashboard (Admin Only)
    Route::get('execution-metrics', [App\Http\Controllers\Admin\ExecutionMetricsController::class, 'index'])->name('admin.execution.metrics');
    Route::get('execution-metrics/{strategy}', [App\Http\Controllers\Admin\ExecutionMetricsController::class, 'show'])->name('admin.execution.detail');
    Route::post('users/{user}/unban', [App\Http\Controllers\AdminController::class, 'unbanUser'])->name('admin.users.unban');
    Route::post('notification', [App\Http\Controllers\AdminController::class, 'sendNotification'])->name('admin.notification.send');
    
    // Impersonation
    Route::post('impersonate/{user}', [App\Http\Controllers\Admin\ImpersonationController::class, 'start'])->name('admin.impersonation.start');
    Route::post('impersonate/stop', [App\Http\Controllers\Admin\ImpersonationController::class, 'stop'])->name('admin.impersonation.stop');
    
    // System Health Dashboard
    Route::get('system-health', [App\Http\Controllers\Admin\SystemHealthController::class, 'index'])->name('admin.health.index');
    Route::get('system-health/check', [App\Http\Controllers\Admin\SystemHealthController::class, 'check'])->name('admin.health.check');
    Route::post('system-health/retry-job/{id}', [App\Http\Controllers\Admin\SystemHealthController::class, 'retryJob'])->name('admin.health.retry-job');
    Route::delete('system-health/delete-job/{id}', [App\Http\Controllers\Admin\SystemHealthController::class, 'deleteJob'])->name('admin.health.delete-job');
    Route::post('system-health/flush-jobs', [App\Http\Controllers\Admin\SystemHealthController::class, 'flushFailedJobs'])->name('admin.health.flush-jobs');
    
    // Revenue Dashboard
    Route::get('revenue', [App\Http\Controllers\Admin\RevenueController::class, 'index'])->name('admin.revenue.index');
    Route::post('revenue/refund/{chargeId}', [App\Http\Controllers\Admin\RevenueController::class, 'refund'])->name('admin.revenue.refund');
    
    // Activity Logs
    Route::get('activity-logs', [App\Http\Controllers\Admin\ActivityLogController::class, 'index'])->name('admin.activity.index');
    Route::get('activity-logs/stats', [App\Http\Controllers\Admin\ActivityLogController::class, 'stats'])->name('admin.activity.stats');
    Route::get('activity-logs/export', [App\Http\Controllers\Admin\ActivityLogController::class, 'export'])->name('admin.activity.export');
});

/*
|--------------------------------------------------------------------------
| Strategy Routes
|--------------------------------------------------------------------------
|
| Routes for managing marketing strategies.
|
*/
Route::middleware(['auth'])->group(function () {
    // Route to display the strategy creation form.
    // GET /strategies/create
    Route::get('/strategies/create', [App\Http\Controllers\StrategyController::class, 'create'])->name('strategies.create');

    // Route to handle the form submission and create a new strategy.
    // POST /strategies
    Route::post('/strategies', [App\Http\Controllers\StrategyController::class, 'store'])->name('strategies.store');

    // Route to display a specific strategy for editing.
    // GET /strategies/{strategy}/edit
    Route::get('/strategies/{strategy}/edit', [App\Http\Controllers\StrategyController::class, 'edit'])->name('strategies.edit');

    // Route to update a strategy.
    // PUT /strategies/{strategy}
    Route::put('/strategies/{strategy}', [App\Http\Controllers\StrategyController::class, 'update'])->name('strategies.update');

    // Route to delete a strategy.
    // DELETE /strategies/{strategy}
    Route::delete('/strategies/{strategy}', [App\Http\Controllers\StrategyController::class, 'destroy'])->name('strategies.destroy');
});
