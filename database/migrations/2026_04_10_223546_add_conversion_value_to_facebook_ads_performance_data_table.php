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
        Schema::table('facebook_ads_performance_data', function (Blueprint $table) {
            $table->decimal('conversion_value', 12, 2)->default(0)->after('conversions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('facebook_ads_performance_data', function (Blueprint $table) {
            $table->dropColumn('conversion_value');
        });
    }
};
