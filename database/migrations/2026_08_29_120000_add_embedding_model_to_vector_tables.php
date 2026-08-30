<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record which model produced each stored vector.
 *
 * GeminiService falls back from the configured embedding model to
 * gemini-embedding-001 when the primary returns 429. Both emit 3072 dimensions,
 * so the write succeeds — but they embed into different spaces, and cosine
 * distance between vectors from different spaces is noise. Nothing recorded
 * which model produced a row, so a 429 storm during a crawl silently degraded
 * search quality with no way to detect which rows were affected, let alone
 * repair only those.
 *
 * Existing rows are backfilled with the configured model. That is an assumption,
 * not a measurement: the fallback only fires on 429, so the configured model
 * produced the overwhelming majority. `embeddings:refresh --mismatched` exists
 * for the rest.
 */
return new class extends Migration
{
    private const TABLES = ['knowledge_bases', 'customer_pages'];

    public function up(): void
    {
        $configured = config('ai.models.embedding');

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'embedding_model')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->string('embedding_model')->nullable()->index();
            });

            DB::table($table)->whereNotNull('embedding')->update(['embedding_model' => $configured]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'embedding_model')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('embedding_model'));
            }
        }
    }
};
