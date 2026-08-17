<?php

namespace App\Console\Commands;

use App\Jobs\ArchiveActivityLogs;
use Illuminate\Console\Command;

/**
 * Run the activity log archive by hand.
 *
 * The scheduler runs this nightly; the command exists for the first run against
 * a backlog, and for checking what would move before it does.
 */
class ArchiveActivityLogsCommand extends Command
{
    protected $signature = 'activity:archive {--days= : Override the retention window} {--dry-run}';

    protected $description = 'Archive activity logs older than the retention window to storage';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('activity.retention_days', 30);

        $cutoff = now()->subDays($days)->startOfDay();

        $pending = \App\Models\ActivityLog::where('created_at', '<', $cutoff)->count();

        $this->line("Retention : {$days} days (cutoff {$cutoff->toDateString()})");
        $this->line("Eligible  : {$pending} row(s)");

        if ($pending === 0) {
            $this->info('Nothing to archive.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing written or deleted.');

            return self::SUCCESS;
        }

        (new ArchiveActivityLogs($days))->handle();

        $this->info('Archive complete. Rows are removed only after their file is written and read back.');

        return self::SUCCESS;
    }
}
