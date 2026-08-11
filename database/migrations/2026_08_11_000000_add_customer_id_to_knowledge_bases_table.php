<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scope knowledge-base entries to a customer rather than a user.
 *
 * Everything else in the retrieval path is customer-scoped — customer_pages,
 * campaigns, strategies. knowledge_bases alone was keyed by user_id, so a user
 * who owns two customers had one shared pool, and strategy generation for one
 * retrieved the other's crawled content as context.
 *
 * That is live, not theoretical: one user pairs voicelawyers.com with
 * sitetospend.com, so a law firm's campaign strategy could be seeded with
 * advertising-software copy.
 *
 * CrawlPage already knows the customer — it writes customer_id to
 * customer_pages on the adjacent line — so nothing but the column was missing.
 *
 * Backfill attributes existing rows by matching the entry's URL host against
 * the websites of the owning user's customers. Rows that cannot be attributed
 * with confidence are deliberately left null and excluded from retrieval:
 * guessing would reintroduce exactly the contamination this removes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
            $table->index(['customer_id', 'user_id']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'user_id']);
            $table->dropConstrainedForeignId('customer_id');
        });
    }

    /**
     * Attribute existing rows to a customer by URL host.
     */
    private function backfill(): void
    {
        $customers = DB::table('customers')
            ->select('id', 'website')
            ->whereNotNull('website')
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'host' => $this->host($c->website)])
            ->filter(fn ($c) => $c['host'] !== null);

        // user_id => [customer ids]
        $ownership = DB::table('customer_user')
            ->select('user_id', 'customer_id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('customer_id')->all());

        DB::table('knowledge_bases')->orderBy('id')->chunkById(500, function ($rows) use ($customers, $ownership) {
            foreach ($rows as $row) {
                $host = $this->host($row->url ?? '');
                if (! $host) {
                    continue;
                }

                $owned = $ownership[$row->user_id] ?? [];
                if ($owned === []) {
                    continue;
                }

                // Only attribute when exactly one of the user's customers matches
                // the host. An ambiguous match is left null on purpose.
                $matches = $customers
                    ->filter(fn ($c) => in_array($c['id'], $owned, true) && $c['host'] === $host)
                    ->pluck('id')
                    ->unique()
                    ->values();

                if ($matches->count() === 1) {
                    DB::table('knowledge_bases')->where('id', $row->id)
                        ->update(['customer_id' => $matches->first()]);
                }
            }
        });
    }

    private function host(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST) ?: null;

        if (! $host && $url !== '') {
            // Bare domains stored without a scheme still need to match.
            $host = parse_url('https://'.ltrim($url, '/'), PHP_URL_HOST) ?: null;
        }

        return $host ? strtolower(preg_replace('/^www\./', '', $host)) : null;
    }
};
