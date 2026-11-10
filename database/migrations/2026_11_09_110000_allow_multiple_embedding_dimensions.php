<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->changeEmbeddingType('vector');
    }

    public function down(): void
    {
        // Postgres rejects this if newer vectors are still present.
        $this->changeEmbeddingType('vector(3072)');
    }

    private function changeEmbeddingType(string $type): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['customer_pages', 'knowledge_bases', 'knowledge_base_chunks'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN embedding TYPE {$type}");
        }
    }
};
