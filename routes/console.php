<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\MonitorCampaignStatus;
use App\Jobs\OptimizeCampaigns;
use App\Jobs\AutomatedCampaignMaintenance;
use App\Jobs\RunCompetitorIntelligence;
use App\Jobs\ProcessDailyAdSpendBilling;
use App\Jobs\RunHealthChecks;
use App\Jobs\CheckCampaignPolicyViolations;
use App\Jobs\HourlyBudgetOptimization;
use App\Jobs\GetKeywordQualityScore;
use App\Jobs\GenerateExecutiveReport;
use App\Jobs\SendDailyPerformanceReports;
use App\Models\Customer;
use App\Models\Campaign;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ============================================================
// HEALTH & MONITORING
// ============================================================

// Campaign status monitoring - checks if campaigns are approved/live
Schedule::job(new MonitorCampaignStatus)->hourly();

// Hourly budget optimization - applies learned per-account multipliers and snapshots performance
Schedule::job(new HourlyBudgetOptimization)->hourly();

// Proactive health checks - API connectivity, token validity, delivery issues
// Runs every 6 hours to catch issues early
Schedule::job(new RunHealthChecks)->everySixHours();

// ============================================================
// DAILY OPERATIONS
// ============================================================

// AI-powered optimization analysis - reviews performance and suggests improvements
Schedule::job(new OptimizeCampaigns)->daily();

// Autonomous campaign maintenance - self-healing, keyword mining, budget intelligence
Schedule::job(new AutomatedCampaignMaintenance)->dailyAt('04:00'); // Run during low-traffic hours

// Daily ad spend billing - bills customers for yesterday's spend, handles failures
Schedule::job(new ProcessDailyAdSpendBilling)->dailyAt('06:00'); // Run after ad networks finalize spend

// Daily performance email - send yesterday's metrics summary to all users
Schedule::job(new SendDailyPerformanceReports)->dailyAt('08:00'); // Run after perf data is fetched

// Policy compliance checks - detects disapprovals and policy violations
Schedule::call(function () {
    \App\Models\Campaign::where('platform_status', 'ENABLED')->each(function ($campaign) {
        CheckCampaignPolicyViolations::dispatch($campaign->id);
    });
})->daily();

// Keyword Quality Score tracking - captures daily QS snapshots for trending
Schedule::call(function () {
    Campaign::where('platform_status', 'ENABLED')
        ->whereNotNull('google_ads_campaign_id')
        ->each(function ($campaign) {
            GetKeywordQualityScore::dispatch($campaign->id);
        });
})->daily();

// ============================================================
// WEEKLY OPERATIONS
// ============================================================

// Competitive intelligence - runs weekly for all customers with active campaigns
Schedule::call(function () {
    Customer::whereHas('campaigns', function ($q) {
        $q->where('platform_status', 'ENABLED');
    })->each(function ($customer) {
        RunCompetitorIntelligence::dispatch($customer);
    });
})->weekly()->sundays()->at('02:00'); // Run Sunday nights

// Weekly executive reports - AI-generated performance summaries per customer
Schedule::call(function () {
    Customer::whereHas('campaigns', function ($q) {
        $q->where('platform_status', 'ENABLED');
    })->each(function ($customer) {
        GenerateExecutiveReport::dispatch($customer->id, 'weekly');
    });
})->weekly()->mondays()->at('07:00'); // Run Monday mornings

// ============================================================
// SEO & MAINTENANCE
// ============================================================

// Sitemap is maintained as a static file in public/sitemap.xml
// and committed to version control. No need to regenerate dynamically.

