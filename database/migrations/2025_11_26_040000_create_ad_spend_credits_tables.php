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
        Schema::create('ad_spend_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            
            // Credit amounts
            $table->decimal('initial_credit_amount', 10, 2)->default(0);
            $table->decimal('current_balance', 10, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            
            // Account status
            $table->string('status')->default('active'); // active, low_balance, depleted, suspended
            $table->string('payment_status')->default('current'); // current, grace_period, failed, paused
            
            // Payment tracking
            $table->timestamp('last_successful_charge_at')->nullable();
            $table->integer('failed_charge_count')->default(0);
            $table->timestamp('grace_period_ends_at')->nullable();
            $table->timestamp('campaigns_paused_at')->nullable();
            
            // Stripe
            $table->string('stripe_payment_method_id')->nullable();
            
            // Notes
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['customer_id', 'status']);
            $table->index('payment_status');
        });

        Schema::create('ad_spend_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_spend_credit_id')->constrained()->onDelete('cascade');
            
            // Transaction details
            $table->string('type'); // credit, deduction, refund, adjustment
            $table->decimal('amount', 10, 2); // Positive for credits, negative for deductions
            $table->decimal('balance_after', 10, 2);
            $table->text('description')->nullable();
            
            // References
            $table->string('stripe_charge_id')->nullable();
            $table->foreignId('campaign_id')->nullable()->constrained()->onDelete('set null');
            
            // Extra data
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['ad_spend_credit_id', 'type']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_spend_transactions');
        Schema::dropIfExists('ad_spend_credits');
    }
};
