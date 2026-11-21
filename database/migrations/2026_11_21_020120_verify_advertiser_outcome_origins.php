<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->char('verified_outcome_origin', 64)->nullable();
            $table->timestampTz('outcome_origin_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['verified_outcome_origin', 'outcome_origin_verified_at']));
    }
};
