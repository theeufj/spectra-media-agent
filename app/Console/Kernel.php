<?php

namespace App\Console;

use App\Models\Customer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\DetectKeywordCannibalization;
use App\Jobs\DetectNegativeKeywordConflicts;
use App\Jobs\GenerateExecutiveReport;
use App\Jobs\OptimizeCampaigns;
use App\Jobs\RecordSiteConversion;
use App\Jobs\ReviewGoogleAdsRecommendations;
use App\Jobs\RunPerformanceAnomalyCheck;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('billing:report-ad-spend')->daily();

        // Fire seven_day_return conversion for customers whose users signed up exactly
        // 7 days ago via a Google Ad (have a stored gclid). Runs daily at 10:00.
        $schedule->call(function () {
            Customer::whereHas('users', fn ($q) =>
                $q->whereNotNull('gclid')
                  ->whereBetween('created_at', [now()->subDays(7)->startOfDay(), now()->subDays(7)->endOfDay()])
            )->each(fn (Customer $c) => RecordSiteConversion::dispatch($c, 'seven_day_return'));
        })->dailyAt('10:00');
        $schedule->command('campaign:fetch-performance-data')->hourly();
        $schedule->job(new RunPerformanceAnomalyCheck)->everyFourHours();
        $schedule->job(new OptimizeCampaigns)->hourly();
        $schedule->job(new ReviewGoogleAdsRecommendations)->dailyAt('04:30');

        // Weekly keyword audit jobs (Monday 07:00)
        $schedule->job(new DetectNegativeKeywordConflicts)->weeklyOn(1, '07:00');
        $schedule->job(new DetectKeywordCannibalization)->weeklyOn(1, '07:30');

        // Quarterly executive reports — first day of each quarter at 08:00
        $schedule->call(function () {
            Customer::whereHas('campaigns', fn($q) => $q->where('status', 'active'))->each(function (Customer $customer) {
                GenerateExecutiveReport::dispatch($customer->id, 'quarterly');
            });
        })->quarterly()->at('08:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
