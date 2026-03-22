<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Upgrade vector columns from 768 (gemini-embedding-001) to 3072 (gemini-embedding-2-preview).
     * Existing embeddings are dropped since they're incompatible with the new dimensions.
     * Run `php artisan embeddings:refresh` after migrating to re-embed all content.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Null out existing 768-dim embeddings (incompatible with 3072)
        DB::statement('UPDATE knowledge_bases SET embedding = NULL');
        DB::statement('UPDATE customer_pages SET embedding = NULL');

        // Alter column dimensions: 768 → 3072
        DB::statement('ALTER TABLE knowledge_bases ALTER COLUMN embedding TYPE vector(3072)');
        DB::statement('ALTER TABLE customer_pages ALTER COLUMN embedding TYPE vector(3072)');
    }

    /**
     * Revert to 768-dim vectors. Embeddings will need to be regenerated.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('UPDATE knowledge_bases SET embedding = NULL');
        DB::statement('UPDATE customer_pages SET embedding = NULL');

        DB::statement('ALTER TABLE knowledge_bases ALTER COLUMN embedding TYPE vector(768)');
        DB::statement('ALTER TABLE customer_pages ALTER COLUMN embedding TYPE vector(768)');
    }
};
