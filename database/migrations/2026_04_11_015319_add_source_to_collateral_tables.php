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
        Schema::table('image_collaterals', function (Blueprint $table) {
            $table->string('source', 20)->default('ai')->after('is_active');
        });

        Schema::table('video_collaterals', function (Blueprint $table) {
            $table->string('source', 20)->default('ai')->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('image_collaterals', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::table('video_collaterals', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
