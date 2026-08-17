<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Services\StorageHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Move activity logs older than the retention window out to storage.
 *
 * The admin audit log now records every mutating request across nineteen
 * controllers rather than three, so the table grows steadily and the console
 * only ever needs the recent end of it. But this is the record of who changed
 * what — deleting a row without a durable copy of it would be a worse version of
 * every bug found in this codebase, because nothing would ever reveal the loss.
 *
 * So the order is: write, verify the write, then delete. A failed or unverified
 * upload leaves the rows exactly where they are, and the next run tries again.
 *
 * Archives are one gzipped JSONL file per day, which is the granularity anyone
 * investigating an incident actually asks for.
 */
class ArchiveActivityLogs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 900;

    /** Rows per chunk. Small enough that a month of logs never lands in memory at once. */
    private const CHUNK = 1000;

    public function __construct(private ?int $retentionDays = null) {}

    public function handle(): void
    {
        $days = $this->retentionDays ?? (int) config('activity.retention_days', 30);
        $cutoff = now()->subDays($days)->startOfDay();

        // get()->pluck(), not ->pluck(): pluck() on the builder rewrites the
        // select clause and discards the DATE() expression, which returns an
        // empty set and makes the job look like it had nothing to do.
        $dates = ActivityLog::where('created_at', '<', $cutoff)
            ->selectRaw('DATE(created_at) as day')
            ->distinct()
            ->orderBy('day')
            ->get()
            ->pluck('day')
            ->map(fn ($day) => substr((string) $day, 0, 10));

        if ($dates->isEmpty()) {
            Log::info('ArchiveActivityLogs: nothing older than the retention window', ['cutoff' => $cutoff->toDateString()]);

            return;
        }

        $archived = 0;
        $failed = 0;

        foreach ($dates as $day) {
            // Per day, so one unwritable file does not block the rest and a
            // retry only redoes the day that failed.
            try {
                $archived += $this->archiveDay((string) $day);
            } catch (\Throwable $e) {
                report($e);
                $failed++;
                Log::error('ArchiveActivityLogs: failed to archive a day, rows left in place', [
                    'day' => $day,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('ArchiveActivityLogs: complete', [
            'days' => $dates->count(),
            'rows_archived' => $archived,
            'days_failed' => $failed,
            'retention_days' => $days,
        ]);
    }

    /**
     * Archive one day's logs and remove them once the file is confirmed present.
     *
     * @return int rows archived
     */
    private function archiveDay(string $day): int
    {
        $query = ActivityLog::whereDate('created_at', $day)->orderBy('id');

        $count = (clone $query)->count();

        if ($count === 0) {
            return 0;
        }

        $lines = [];

        // JSONL rather than a JSON array: a truncated file still yields every
        // complete line before the break, and grep works on it directly.
        $query->chunk(self::CHUNK, function ($rows) use (&$lines) {
            foreach ($rows as $row) {
                $lines[] = json_encode($row->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        });

        $path = "activity-logs/{$day}.jsonl.gz";
        $payload = gzencode(implode("\n", $lines)."\n", 6);

        if ($payload === false) {
            throw new \RuntimeException("Could not compress activity logs for {$day}");
        }

        [, $url] = StorageHelper::put($path, $payload, 'application/gzip');

        // Verify before deleting. A put() that reported success but wrote
        // nothing would otherwise take the only copy with it.
        if (! StorageHelper::exists($path)) {
            throw new \RuntimeException("Archive for {$day} was written but cannot be read back");
        }

        $deleted = ActivityLog::whereDate('created_at', $day)->delete();

        Log::info('ArchiveActivityLogs: day archived', [
            'day' => $day,
            'rows' => $deleted,
            'path' => $path,
            'url' => $url,
        ]);

        return $deleted;
    }
}
