<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the early-exit terms were assessed for this customer (BYO-account
 * build abandoned inside the minimum period). Also the idempotency guard:
 * a customer is assessed once, however many triggers fire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('early_exit_assessed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('early_exit_assessed_at');
        });
    }
};
