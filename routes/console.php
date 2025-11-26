<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\MonitorCampaignStatus;
use App\Jobs\OptimizeCampaigns;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new MonitorCampaignStatus)->hourly();
Schedule::job(new OptimizeCampaigns)->daily();
