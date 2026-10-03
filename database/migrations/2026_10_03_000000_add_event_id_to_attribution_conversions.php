<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attribution_conversions', function (Blueprint $table) {
            $table->uuid('event_id')->nullable();
            $table->unique(['customer_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('attribution_conversions', function (Blueprint $table) {
            $table->dropUnique(['customer_id', 'event_id']);
            $table->dropColumn('event_id');
        });
    }
};
