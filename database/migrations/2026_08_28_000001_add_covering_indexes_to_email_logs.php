<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The only reader of email_logs (the admin customer profile) runs
 * where(customer_id) OR (customer_id IS NULL AND to_email IN …)
 * ORDER BY created_at DESC LIMIT 100. The original single-column indexes
 * couldn't serve the ordering, so Postgres fetched every matching row of an
 * append-only table and top-N sorted per page view. Composite indexes let
 * each OR branch walk its index already ordered; the single-column indexes
 * become redundant prefixes and go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->index(['customer_id', 'created_at']);
            $table->index(['to_email', 'created_at']);
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['to_email']);
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->index('customer_id');
            $table->index('to_email');
            $table->dropIndex(['customer_id', 'created_at']);
            $table->dropIndex(['to_email', 'created_at']);
        });
    }
};
