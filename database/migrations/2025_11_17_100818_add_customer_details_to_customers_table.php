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
        Schema::table('customers', function (Blueprint $table) {
            $table->string('business_type')->nullable()->after('name');
            $table->text('description')->nullable()->after('business_type');
            $table->string('country')->nullable()->after('description');
            $table->string('timezone')->default('America/New_York')->after('country');
            $table->string('currency_code')->default('USD')->after('timezone');
            $table->string('website')->nullable()->after('currency_code');
            $table->string('phone')->nullable()->after('website');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['business_type', 'description', 'country', 'timezone', 'currency_code', 'website', 'phone']);
        });
    }
};
