<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Put recommendations.target_entity back to json.
 *
 * 2026_05_19_110000 made three columns nullable with `string('target_entity')
 * ->nullable()->change()`. change() applies the whole declaration, so that
 * silently narrowed a json column (2025_11_16 create) to varchar(255) while
 * leaving `parameters` json beside it. The model still casts target_entity to
 * array, so every write JSON-encodes into 255 characters; on overflow
 * OptimizeCampaigns' insert aborts the campaign *after* applyRecommendation()
 * has pushed the change to the platform and before last_optimized_at is
 * stamped, so the next run does it all again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // varchar -> json is not an implicit cast in Postgres and change()
            // compiles no USING clause, so the type change is raw SQL.
            // nullif(): '' is not valid JSON and would abort the deploy over a
            // row that holds nothing worth keeping.
            DB::statement('ALTER TABLE recommendations ALTER COLUMN target_entity TYPE json USING nullif(target_entity, \'\')::json');
            DB::statement('ALTER TABLE recommendations ALTER COLUMN target_entity DROP NOT NULL');

            return;
        }

        Schema::table('recommendations', function (Blueprint $table) {
            $table->json('target_entity')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // json -> varchar needs a USING clause for the same reason, and
            // anything longer than 255 characters is lost on the way back.
            DB::statement('ALTER TABLE recommendations ALTER COLUMN target_entity TYPE varchar(255) USING target_entity::text');

            return;
        }

        Schema::table('recommendations', function (Blueprint $table) {
            $table->string('target_entity')->nullable()->change();
        });
    }
};
