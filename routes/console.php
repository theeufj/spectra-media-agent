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
use App\Jobs\GenerateMonthlyReport;
use App\Jobs\SendDailyPerformanceReports;
use App\Jobs\FetchGoogleAdsPerformanceData;
use App\Jobs\FetchFacebookAdsPerformanceData;
use App\Jobs\FetchMicrosoftAdsPerformanceData;
use App\Jobs\FetchLinkedInAdsPerformanceData;
use App\Models\Customer;
use App\Models\Campaign;
use App\Models\User;
use App\Notifications\ScheduledJobFailed;

/**
 * Notify admin users when a critical scheduled job fails.
 */
if (!function_exists('notifyAdminOnFailure')) {
    function notifyAdminOnFailure(string $jobName): \Closure
    {
        return function () use ($jobName) {
            $admins = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->get();
            foreach ($admins as $admin) {
                $admin->notify(new ScheduledJobFailed($jobName, 'Scheduled execution failed'));
            }
        };
    }
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ============================================================
// HEALTH & MONITORING
// ============================================================

// Campaign status monitoring - checks if campaigns are approved/live
Schedule::job(new MonitorCampaignStatus)->hourly()->withoutOverlapping();

// Hourly budget optimization - applies learned per-account multipliers and snapshots performance
Schedule::job(new HourlyBudgetOptimization)->hourly()->withoutOverlapping();

// Proactive health checks - API connectivity, token validity, delivery issues
// Runs every 6 hours to catch issues early
Schedule::job(new RunHealthChecks)->everySixHours()->withoutOverlapping()->onFailure(notifyAdminOnFailure('RunHealthChecks'));

// Performance data fetch - pull metrics from all ad platforms for active campaigns
Schedule::call(function () {
    Campaign::withDeployedPlatforms()->each(function ($campaign) {
        if ($campaign->google_ads_campaign_id) {
            FetchGoogleAdsPerformanceData::dispatch($campaign);
        }
        if ($campaign->facebook_ads_campaign_id) {
            FetchFacebookAdsPerformanceData::dispatch($campaign);
        }
        if ($campaign->microsoft_ads_campaign_id) {
            FetchMicrosoftAdsPerformanceData::dispatch($campaign);
        }
        if ($campaign->linkedin_campaign_id) {
            FetchLinkedInAdsPerformanceData::dispatch($campaign);
        }
    });
})->name('fetch-platform-performance-data')->hourly()->withoutOverlapping();

// ============================================================
// DAILY OPERATIONS
// ============================================================

// AI-powered optimization analysis - reviews performance and suggests improvements
Schedule::job(new OptimizeCampaigns)->daily()->withoutOverlapping()->onFailure(notifyAdminOnFailure('OptimizeCampaigns'));

// Autonomous campaign maintenance - self-healing, keyword mining, budget intelligence
Schedule::job(new AutomatedCampaignMaintenance)->dailyAt('04:00')->withoutOverlapping()->onFailure(notifyAdminOnFailure('AutomatedCampaignMaintenance'));

// Daily ad spend billing - bills customers for yesterday's spend, handles failures
Schedule::job(new ProcessDailyAdSpendBilling)->dailyAt('06:00')->withoutOverlapping()->onFailure(notifyAdminOnFailure('ProcessDailyAdSpendBilling'));

// Daily performance email - send yesterday's metrics summary to all users
Schedule::job(new SendDailyPerformanceReports)->dailyAt('08:00')->withoutOverlapping();

// Policy compliance checks - detects disapprovals and policy violations
Schedule::call(function () {
    \App\Models\Campaign::withDeployedPlatforms()->each(function ($campaign) {
        CheckCampaignPolicyViolations::dispatch($campaign->id);
    });
})->name('check-campaign-policy-violations')->daily()->withoutOverlapping();

// Keyword Quality Score tracking - captures daily QS snapshots for trending
Schedule::call(function () {
    Campaign::withDeployedPlatforms()
        ->whereNotNull('google_ads_campaign_id')
        ->each(function ($campaign) {
            GetKeywordQualityScore::dispatch($campaign->id);
        });
})->name('get-keyword-quality-scores')->daily()->withoutOverlapping();

// ============================================================
// WEEKLY OPERATIONS
// ============================================================

// Competitive intelligence - runs weekly for all customers with active campaigns
Schedule::call(function () {
    Customer::whereHas('campaigns', function ($q) {
        $q->withDeployedPlatforms();
    })->each(function ($customer) {
        RunCompetitorIntelligence::dispatch($customer);
    });
})->name('run-competitor-intelligence')->weekly()->sundays()->at('02:00')->withoutOverlapping(); // Run Sunday nights

// Weekly executive reports - AI-generated performance summaries per customer
Schedule::call(function () {
    Customer::whereHas('campaigns', function ($q) {
        $q->withDeployedPlatforms();
    })->each(function ($customer) {
        GenerateExecutiveReport::dispatch($customer->id, 'weekly');
    });
})->name('generate-weekly-executive-reports')->weekly()->mondays()->at('07:00')->withoutOverlapping(); // Run Monday mornings

// ============================================================
// MONTHLY OPERATIONS
// ============================================================

// Monthly executive reports - detailed monthly performance summaries with PDF
Schedule::call(function () {
    Customer::whereHas('campaigns', function ($q) {
        $q->withDeployedPlatforms();
    })->each(function ($customer) {
        GenerateMonthlyReport::dispatch($customer->id);
    });
})->name('generate-monthly-reports')->monthlyOn(1, '07:00')->withoutOverlapping(); // Run 1st of each month at 7am

// Cross-channel budget rebalance - check all auto-rebalance allocations
Schedule::job(new \App\Jobs\WeeklyBudgetRebalance)->dailyAt('06:00')->withoutOverlapping();

// CRM sync - sync offline conversions from connected CRMs
Schedule::call(function () {
    \App\Models\CrmIntegration::where('status', 'connected')->each(function ($integration) {
        \App\Jobs\SyncCrmConversions::dispatch($integration->id);
    });
})->name('sync-crm-conversions')->everyFourHours()->withoutOverlapping();

// Product feed sync - sync Merchant Center product feeds
Schedule::call(function () {
    \App\Models\ProductFeed::where('status', 'active')
        ->where(function ($q) {
            $q->where('sync_frequency', 'hourly')
              ->orWhere(function ($q2) {
                  $q2->where('sync_frequency', 'daily')
                     ->where(function ($q3) {
                         $q3->whereNull('last_synced_at')
                            ->orWhere('last_synced_at', '<', now()->subHours(20));
                     });
              });
        })
        ->each(function ($feed) {
            \App\Jobs\SyncProductFeed::dispatch($feed->id);
        });
})->name('sync-product-feeds')->hourly()->withoutOverlapping();

// ============================================================
// SEO & MAINTENANCE
// ============================================================

// Daily keyword rank tracking - tracks SEO positions for all customers with keywords
Schedule::call(function () {
    Customer::whereHas('keywords', fn ($q) => $q->where('status', 'active'))
        ->each(function ($customer) {
            \App\Jobs\TrackKeywordRankings::dispatch($customer->id);
        });
})->name('track-keyword-rankings')->dailyAt('05:00')->withoutOverlapping();

// Sitemap is maintained as a static file in public/sitemap.xml
// and committed to version control. No need to regenerate dynamically.

// Weekly cleanup - remove expired proposals and temp files
Schedule::job(new \App\Jobs\CleanupTemporaryFiles)->weeklyOn(0, '03:00')->withoutOverlapping();

// Daily sandbox cleanup - remove expired sandbox environments
Schedule::job(new \App\Jobs\CleanupExpiredSandboxes)->dailyAt('03:30')->withoutOverlapping();

// ============================================================
// DATABASE BACKUPS
// ============================================================

// Daily database backup — runs at 02:00 before maintenance window
Schedule::command('backup:run --only-db')->dailyAt('02:00')->withoutOverlapping()->onFailure(notifyAdminOnFailure('backup:run'));

// Cleanup old backups per retention policy (7d all, 16d daily, 8w weekly, 4m monthly, 2y yearly)
Schedule::command('backup:clean')->dailyAt('02:30')->withoutOverlapping();

// Monitor backup health — alerts if latest backup is missing or too old
Schedule::command('backup:monitor')->dailyAt('03:00')->withoutOverlapping();

