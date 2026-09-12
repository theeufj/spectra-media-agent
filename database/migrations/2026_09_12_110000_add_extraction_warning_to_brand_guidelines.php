<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why the extracted brand may not be the customer's.
 *
 * `extraction_quality_score` measures how cleanly the page parsed, and a parked
 * domain parses beautifully — one scored 94 while describing the domain broker
 * squatting on the address rather than the signup's business. Nothing recorded
 * that doubt, so the review screen presented it as fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_guidelines', function (Blueprint $table) {
            $table->text('extraction_warning')->nullable()->after('extraction_quality_score');
        });
    }

    public function down(): void
    {
        Schema::table('brand_guidelines', function (Blueprint $table) {
            $table->dropColumn('extraction_warning');
        });
    }
};
