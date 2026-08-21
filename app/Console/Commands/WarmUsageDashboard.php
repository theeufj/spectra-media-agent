<?php

namespace App\Console\Commands;

use App\Services\Analytics\AdoptionMetrics;
use App\Services\Analytics\UsagePeriod;
use Illuminate\Console\Command;

/**
 * Keeps the admin usage dashboard's cached sections warm.
 *
 * admin.dashboard is the post-2FA redirect target and both admin nav links, so
 * a cold cache lands directly in a human's login path. Cache::flexible() already
 * serves stale-while-revalidating, but only once there IS a stale value — the
 * very first read after an entry expires still pays full price. This makes that
 * first read a scheduled one rather than an admin's.
 */
class WarmUsageDashboard extends Command
{
    protected $signature = 'admin:warm-usage-dashboard {--period=30 : Window to warm}';

    protected $description = 'Precompute the admin usage dashboard so nobody waits on a cold cache';

    public function handle(): int
    {
        $period = UsagePeriod::fromRequest($this->option('period'));
        $metrics = new AdoptionMetrics($period);

        // Deliberately NOT wrapped in a swallowing try/catch: if warming fails
        // the run must be recorded as failed so it surfaces, rather than
        // reporting success while the cache stays cold.
        $started = microtime(true);

        $metrics->funnel();
        $metrics->statusDrift();
        $metrics->timeToValue();
        $metrics->featureBreadth();
        $metrics->breadthHistogram();

        $this->info(sprintf(
            'Warmed usage dashboard (%s) in %dms',
            $period->label,
            (int) ((microtime(true) - $started) * 1000),
        ));

        return self::SUCCESS;
    }
}
