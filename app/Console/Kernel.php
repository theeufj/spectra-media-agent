<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\EvaluateABTests;
use App\Jobs\RunPerformanceAnomalyCheck;
use App\Models\Campaign;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('billing:report-ad-spend')->daily();
        $schedule->command('campaign:fetch-performance-data')->hourly();
        $schedule->job(new EvaluateABTests)->dailyAt('06:00');
        $schedule->job(new RunPerformanceAnomalyCheck)->everyFourHours();
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
