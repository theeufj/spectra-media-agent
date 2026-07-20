<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\MonitorCampaignStatus;
use App\Jobs\OptimizeCampaigns;
use App\Jobs\AutomatedCampaignMaintenance;
use App\Jobs\RunSelfHealingChecks;
use App\Jobs\RunStrategicDiagnosis;
use App\Jobs\AutoStartABTests;
use App\Jobs\RunCompetitorIntelligence;
use App\Jobs\ProcessDailyAdSpendBilling;
use App\Jobs\RunHealthChecks;
use App\Jobs\CheckCampaignPolicyViolations;
use App\Jobs\HourlyBudgetOptimization;
use App\Jobs\GetKeywordQualityScore;
use App\Jobs\ReviewGoogleAdsRecommendations;
use App\Jobs\RunPerformanceAnomalyCheck;
use App\Jobs\DetectNegativeKeywordConflicts;
use App\Jobs\DetectKeywordCannibalization;
use App\Jobs\ExpandBroadMatchKeywords;
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
use App\Jobs\RecordSiteConversion;
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
// BILLING REPORTING
// ============================================================

// Ad spend billing report - daily summary for accounting
Schedule::command('billing:report-ad-spend')->daily()->withoutOverlapping();

// Fire seven_day_return conversion for users who signed up via Google Ad exactly 7 days ago
Schedule::call(function () {
    Customer::whereHas('users', fn ($q) =>
        $q->whereNotNull('gclid')
          ->whereBetween('created_at', [now()->subDays(7)->startOfDay(), now()->subDays(7)->endOfDay()])
    )->each(fn (Customer $c) => RecordSiteConversion::dispatch($c, 'seven_day_return'));
})->name('record-seven-day-return-conversions')->dailyAt('10:00')->withoutOverlapping();

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

// Pause ad groups that spend without converting (deterministic; reported in the daily email).
Schedule::job(new \App\Jobs\PauseWastefulAdGroups)->dailyAt('02:30')->withoutOverlapping()->onFailure(notifyAdminOnFailure('PauseWastefulAdGroups'));

// Self-healing checks — scan for disapproved ads and rewrite/resubmit every 4 hours
Schedule::job(new RunSelfHealingChecks)->everyFourHours()->withoutOverlapping()->onFailure(notifyAdminOnFailure('RunSelfHealingChecks'));

// Strategic diagnosis — daily deep audit for conversion starvation, PMax gaps, traffic quality
Schedule::job(new RunStrategicDiagnosis)->dailyAt('06:00')->withoutOverlapping()->onFailure(notifyAdminOnFailure('RunStrategicDiagnosis'));

// Performance anomaly detection — intra-day CTR/CPC/CVR/delivery alerts
Schedule::job(new RunPerformanceAnomalyCheck)->everyFourHours()->withoutOverlapping()->onFailure(notifyAdminOnFailure('RunPerformanceAnomalyCheck'));

// Automation health monitor — alerts admins when an optimization job goes stale or
// starts failing (reads the agent_runs trace). Backstop against silent failures.
Schedule::job(new \App\Jobs\MonitorAgentHealth)->everySixHours()->withoutOverlapping()->onFailure(notifyAdminOnFailure('MonitorAgentHealth'));

// Auto-start A/B tests — create headline split tests for live strategies with no active test
Schedule::job(new AutoStartABTests)->dailyAt('05:30')->withoutOverlapping()->onFailure(notifyAdminOnFailure('AutoStartABTests'));

// Autonomous A/B Test evaluation - tracks significance and drops losers
Schedule::job(new \App\Jobs\EvaluateABTests)->dailyAt('06:00')->withoutOverlapping()->onFailure(notifyAdminOnFailure('EvaluateABTests'));

// Bidding strategy progression — graduates campaigns to smarter Smart Bidding as data matures
Schedule::job(new \App\Jobs\EvaluateBiddingStrategyProgression)
    ->weekly()->tuesdays()->at('05:00')
    ->withoutOverlapping()
    ->onFailure(notifyAdminOnFailure('EvaluateBiddingStrategyProgression'));

// DSA campaign management — creates/expands Dynamic Search Ads from website knowledge base
Schedule::job(new \App\Jobs\ManageDSACampaigns)
    ->weekly()->wednesdays()->at('04:00')
    ->withoutOverlapping()
    ->onFailure(notifyAdminOnFailure('ManageDSACampaigns'));

// Autonomous campaign maintenance - self-healing, keyword mining, budget intelligence
Schedule::job(new AutomatedCampaignMaintenance)->dailyAt('04:00')->withoutOverlapping()->onFailure(notifyAdminOnFailure('AutomatedCampaignMaintenance'));

// Google Ads recommendation review — dismisses auto-handled recommendations and alerts
// admins if Google has flagged anything that suggests our agents made a bad decision
Schedule::job(new ReviewGoogleAdsRecommendations)->dailyAt('04:30')->withoutOverlapping()->onFailure(notifyAdminOnFailure('ReviewGoogleAdsRecommendations'));

// Daily ad spend billing - bills customers for yesterday's spend, handles failures.
// Runs at 08:00 UTC to ensure Google Ads data is finalised for all timezones including US Pacific (UTC-8).
Schedule::job(new ProcessDailyAdSpendBilling)->dailyAt('08:00')->withoutOverlapping()->onFailure(notifyAdminOnFailure('ProcessDailyAdSpendBilling'));

// Weekly ad spend reconciliation - compares platform spend to ledger deductions and
// alerts admins on divergence (alert-only, no auto-correction). (BILL-7)
Schedule::job(new \App\Jobs\ReconcileAdSpend)->weeklyOn(1, '09:00')->withoutOverlapping()->onFailure(notifyAdminOnFailure('ReconcileAdSpend'));

// Daily performance email - send yesterday's metrics summary to all users
Schedule::job(new SendDailyPerformanceReports)->dailyAt('08:00')->withoutOverlapping();

// Policy compliance checks - detects disapprovals and policy violations
Schedule::call(function () {
    \App\Models\Campaign::withDeployedPlatforms()->each(function ($campaign) {
        CheckCampaignPolicyViolations::dispatch($campaign->id);
    });
})->name('check-campaign-policy-violations')->hourly()->withoutOverlapping();

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

// Audience intelligence - segment and sync customer match lists
Schedule::job(new \App\Jobs\RunAudienceIntelligence)->weekly()->sundays()->at('03:00')->withoutOverlapping()->onFailure(notifyAdminOnFailure('RunAudienceIntelligence'));

// Negative keyword conflict detection — finds negatives blocking active keywords
Schedule::job(new DetectNegativeKeywordConflicts)->weekly()->mondays()->at('03:00')->withoutOverlapping()->onFailure(notifyAdminOnFailure('DetectNegativeKeywordConflicts'));

// Keyword cannibalization detection — finds duplicate keywords competing across ad groups
Schedule::job(new DetectKeywordCannibalization)->weekly()->mondays()->at('03:30')->withoutOverlapping()->onFailure(notifyAdminOnFailure('DetectKeywordCannibalization'));

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
Schedule::job(new \App\Jobs\WeeklyBudgetRebalance)->weekly()->mondays()->at('06:00')->withoutOverlapping();

// Quarterly executive reports — first day of each quarter at 08:00
Schedule::call(function () {
    Customer::whereHas('campaigns', fn ($q) => $q->where('status', 'active'))->each(function (Customer $customer) {
        \App\Jobs\GenerateExecutiveReport::dispatch($customer->id, 'quarterly');
    });
})->name('generate-quarterly-executive-reports')->quarterly()->at('08:00')->withoutOverlapping();

// Broad match keyword expansion — prunes poor performers, generates new keyword suggestions
Schedule::call(function () {
    Campaign::withDeployedPlatforms()
        ->whereNotNull('google_ads_campaign_id')
        ->each(function ($campaign) {
            ExpandBroadMatchKeywords::dispatch($campaign);
        });
})->name('expand-broad-match-keywords')->monthlyOn(5, '04:00')->withoutOverlapping()->onFailure(notifyAdminOnFailure('ExpandBroadMatchKeywords'));

// Seasonal strategy shift — adjusts budgets and bidding for current season at the start of each month
Schedule::call(function () {
    $month = now()->month;
    $season = match (true) {
        in_array($month, [3, 4, 5])   => 'spring',
        in_array($month, [6, 7, 8])   => 'summer',
        in_array($month, [9, 10, 11]) => 'autumn',
        default                        => 'winter',
    };

    \App\Models\Campaign::withDeployedPlatforms()->each(function ($campaign) use ($season) {
        \App\Jobs\ApplySeasonalStrategyShift::dispatch($campaign->id, $season);
    });
})->name('apply-seasonal-strategy-shift')->monthly()->withoutOverlapping();

// CRM sync - sync offline conversions from connected CRMs.
// Include 'error' so a transient failure self-heals, and 'syncing' only when stale
// (>2h) so a crash-stranded run is retried without racing a genuinely in-flight sync.
Schedule::call(function () {
    \App\Models\CrmIntegration::query()
        ->where(function ($q) {
            $q->whereIn('status', ['connected', 'error'])
              ->orWhere(function ($q) {
                  $q->where('status', 'syncing')
                    ->where('updated_at', '<', now()->subHours(2));
              });
        })
        ->each(function ($integration) {
            \App\Jobs\SyncCrmConversions::dispatch($integration->id);
        });
})->name('sync-crm-conversions')->everyFourHours()->withoutOverlapping();

// Re-drive offline-conversion uploads: retries pending rows and previously-failed
// rows with retries left. Without this, failed uploads were never re-attempted. (JOB-3)
Schedule::call(function () {
    \App\Models\OfflineConversion::query()
        ->where(function ($q) {
            $q->where('upload_status', 'pending')
              ->orWhere(function ($q) {
                  $q->where('upload_status', 'failed')
                    ->where('upload_attempts', '<', \App\Jobs\UploadOfflineConversions::MAX_ATTEMPTS);
              });
        })
        ->distinct()
        ->pluck('customer_id')
        ->each(function ($customerId) {
            \App\Jobs\UploadOfflineConversions::dispatch($customerId);
        });
})->name('retry-offline-conversions')->hourly()->withoutOverlapping();

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
// PMAX ASSET OPTIMIZATION
// ============================================================

// Weekly PMax asset audit — checks all active PMax campaigns for low-performing assets
// and auto-generates AI replacement copy for headlines/descriptions
Schedule::command('pmax:check-assets')->weekly()->withoutOverlapping();

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

// Conversion tracking health check — alert admins if active campaigns have 0 conversions in 30 days
Schedule::job(new \App\Jobs\VerifyConversionTracking)
    ->weekly()->sundays()->at('04:00')
    ->withoutOverlapping()
    ->onFailure(notifyAdminOnFailure('VerifyConversionTracking'));

// Facebook System User token health check — alert admins if token is expired/invalid
Schedule::job(new \App\Jobs\RefreshFacebookTokens)
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onFailure(notifyAdminOnFailure('RefreshFacebookTokens'));

