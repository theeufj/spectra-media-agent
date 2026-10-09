<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_audits', function (Blueprint $table) {
            $table->json('indexing_analysis')->nullable();
        });
        Schema::table('seo_rankings', function (Blueprint $table) {
            // Keep legacy snapshot integers intact; never imply they were
            // first-party Search Console averages.
            $table->string('source')->default('legacy_unknown');
            $table->decimal('average_position', 10, 3)->nullable();
            $table->decimal('previous_average_position', 10, 3)->nullable();
            $table->decimal('average_change', 10, 3)->nullable();
            $table->date('reporting_start')->nullable();
            $table->date('reporting_end')->nullable();
            $table->unsignedBigInteger('clicks')->nullable();
            $table->unsignedBigInteger('impressions')->nullable();
            $table->decimal('ctr', 10, 6)->nullable();
        });
        // Only modern measurements are unique. Existing legacy duplicates
        // remain intact, while concurrent refreshes cannot double-count data.
        DB::statement("CREATE UNIQUE INDEX seo_rankings_first_party_measurement ON seo_rankings (customer_id, keyword, date, source) WHERE source = 'google_search_console'");
    }

    public function down(): void
    {
        Schema::table('seo_rankings', fn (Blueprint $table) => $table->dropIndex('seo_rankings_first_party_measurement'));
        Schema::table('seo_audits', fn (Blueprint $table) => $table->dropColumn('indexing_analysis'));
        Schema::table('seo_rankings', fn (Blueprint $table) => $table->dropColumn([
            'source', 'average_position', 'previous_average_position', 'average_change',
            'reporting_start', 'reporting_end', 'clicks', 'impressions', 'ctr',
        ]));
    }
};
