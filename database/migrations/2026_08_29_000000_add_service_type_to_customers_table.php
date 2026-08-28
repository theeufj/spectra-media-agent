<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-and-done customers: pay a single USD setup fee, we build their Google
 * Ads presence (account or their own, campaigns, tracking) and hand over —
 * no subscription, no ongoing management.
 *
 * service_type: 'managed' (default, subscription customers) or 'setup_only'.
 * setup_fee_paid_at: when the one-time fee was confirmed paid.
 * handover_at: when we stepped away — recurring agents never touch
 * setup_only customers, and this marks the engagement closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('service_type')->default('managed')->index();
            $table->timestamp('setup_fee_paid_at')->nullable();
            $table->timestamp('handover_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['service_type', 'setup_fee_paid_at', 'handover_at']);
        });
    }
};
