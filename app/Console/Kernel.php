<?php

namespace App\Console;

use App\Models\Customer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\DetectKeywordCannibalization;
use App\Jobs\DetectNegativeKeywordConflicts;
use App\Jobs\GenerateExecutiveReport;
use App\Jobs\RunPerformanceAnomalyCheck;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('billing:report-ad-spend')->daily();
        $schedule->command('campaign:fetch-performance-data')->hourly();
        $schedule->job(new RunPerformanceAnomalyCheck)->everyFourHours();

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
