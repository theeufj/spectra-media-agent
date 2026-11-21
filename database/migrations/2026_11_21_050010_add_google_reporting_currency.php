<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_ads_performance_data', function (Blueprint $table) {
            $table->string('google_account_id')->nullable();
            $table->char('currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('google_ads_performance_data', fn (Blueprint $table) => $table->dropColumn(['google_account_id', 'currency']));
    }
};
