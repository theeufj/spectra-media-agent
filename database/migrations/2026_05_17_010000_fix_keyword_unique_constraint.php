<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove duplicates introduced by the old updateOrCreate key before adding the unique constraint.
        // Keep the most recently updated row for each (customer_id, keyword_text, match_type, campaign_id) tuple.
        DB::statement("
            DELETE FROM keywords
            WHERE id NOT IN (
                SELECT DISTINCT ON (customer_id, keyword_text, match_type, campaign_id) id
                FROM keywords
                ORDER BY customer_id, keyword_text, match_type, campaign_id, updated_at DESC
            )
        ");

        Schema::table('keywords', function (Blueprint $table) {
            $table->unique(['customer_id', 'keyword_text', 'match_type', 'campaign_id'], 'keywords_unique_per_match_type');
        });
    }

    public function down(): void
    {
        Schema::table('keywords', function (Blueprint $table) {
            $table->dropUnique('keywords_unique_per_match_type');
        });
    }
};
