<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('brand_guidelines', function (Blueprint $table) {
            $table->boolean('user_verified')->default(false)->after('extraction_quality_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brand_guidelines', function (Blueprint $table) {
            $table->dropColumn('user_verified');
        });
    }
};
