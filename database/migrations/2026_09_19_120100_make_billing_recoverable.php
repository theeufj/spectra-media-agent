<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Never silently merge balances or delete financial history.
        if (DB::table('ad_spend_credits')->select('customer_id')->groupBy('customer_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Duplicate ad spend accounts require ledger reconciliation before this migration.');
        }
        Schema::table('ad_spend_credits', fn (Blueprint $table) => $table->unique('customer_id'));
        Schema::table('ad_spend_billing_runs', function (Blueprint $table) {
            $table->dropUnique(['customer_id', 'billing_date']);
            $table->date('spend_date')->nullable();
            $table->string('status')->default('legacy');
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unique(['customer_id', 'spend_date']);
        });
        Schema::create('ad_spend_charge_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key')->unique();
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('payment_intent_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_spend_charge_attempts');
        Schema::table('ad_spend_billing_runs', function (Blueprint $table) {
            $table->dropUnique(['customer_id', 'spend_date']);
            $table->dropColumn(['spend_date', 'status', 'lease_token', 'lease_expires_at', 'completed_at', 'attempts']);
        });
        Schema::table('ad_spend_credits', fn (Blueprint $table) => $table->dropUnique(['customer_id']));
    }
};
