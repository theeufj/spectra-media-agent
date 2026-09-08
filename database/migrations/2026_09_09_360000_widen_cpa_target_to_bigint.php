<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * cpa_target stores micros, so int4 topped out at a $2,147.48 target CPA.
 *
 * Above that Postgres answers with SQLSTATE 22003 in the middle of
 * GenerateStrategy's per-platform loop, which abandons the strategies already
 * written for the earlier platforms of the same campaign. B2B targets clear
 * $2,147 routinely, and strategy_versions (unsignedInteger) only got as far as
 * $4,294.97 before doing the same thing on rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        // int4 -> int8 is an implicit cast in Postgres, so change() is enough
        // here; it still has to restate nullable(), because change() applies
        // the whole declaration and would otherwise re-add NOT NULL.
        Schema::table('strategies', function (Blueprint $table) {
            $table->bigInteger('cpa_target')->nullable()->change();
        });

        Schema::table('strategy_versions', function (Blueprint $table) {
            // Unsigned before, unsigned after: widening the column should not
            // also start accepting a negative target CPA.
            $table->unsignedBigInteger('cpa_target')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->integer('cpa_target')->nullable()->change();
        });

        Schema::table('strategy_versions', function (Blueprint $table) {
            $table->unsignedInteger('cpa_target')->nullable()->change();
        });
    }
};
