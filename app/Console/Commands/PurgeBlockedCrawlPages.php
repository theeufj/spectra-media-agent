<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete crawled rows that are a site's refusal rather than its content.
 *
 * Before CrawlPage checked what it had been handed, a rate-limit notice was
 * stored as page text and embedded at cost. One store accumulated 1,205 rows
 * reading "local_rate_limited" and 27 holding the browser's error page. They
 * are worse than absent: a knowledge base full of refusals looks identical to
 * one full of thin pages, and anything reading it — brand extraction, the
 * first-campaign generator, the support assistant's tools — treats them as
 * things the business said about itself.
 *
 * Dry by default. Deleting customer content is not something a command should
 * do because it was run without arguments.
 */
class PurgeBlockedCrawlPages extends Command
{
    protected $signature = 'crawl:purge-blocked {--apply : Actually delete, rather than reporting what would go}';

    protected $description = 'Remove crawled pages that captured a rate-limit or block notice instead of content';

    /** Kept in step with CrawlPage::BLOCKED_SIGNATURES. */
    private const SIGNATURES = [
        'local_rate_limited',
        'There was a problem loading this website',
        'Too many requests',
        'Rate limit exceeded',
        'Checking your browser before accessing',
        'Enable JavaScript and cookies to continue',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        foreach (['knowledge_bases', 'customer_pages'] as $table) {
            $query = DB::table($table)->where(function ($q) {
                foreach (self::SIGNATURES as $signature) {
                    $q->orWhere('content', 'ILIKE', '%'.$signature.'%');
                }
            });

            $count = (clone $query)->count();

            if ($count === 0) {
                $this->info("{$table}: nothing to remove.");

                continue;
            }

            // Shown per customer because the damage is not evenly spread — one
            // store accounts for nearly all of it, and that is worth seeing
            // before agreeing to the deletion.
            $byCustomer = (clone $query)
                ->selectRaw('customer_id, count(*) as rows')
                ->groupBy('customer_id')
                ->orderByDesc('rows')
                ->get();

            $this->warn("{$table}: {$count} blocked rows");

            foreach ($byCustomer as $row) {
                $this->line(sprintf('   customer %-6s %d rows', $row->customer_id ?? 'null', $row->rows));
            }

            if ($apply) {
                $deleted = $query->delete();
                $this->info("   deleted {$deleted}");
            }
        }

        if (! $apply) {
            $this->newLine();
            $this->comment('Dry run. Re-run with --apply to delete.');
            $this->comment('Re-crawl afterwards: the pages themselves were never read, only refused.');
        }

        return self::SUCCESS;
    }
}
