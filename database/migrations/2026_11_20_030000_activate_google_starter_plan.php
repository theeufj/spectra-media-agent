<?php

use Database\Seeders\StarterPlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'legacy_stripe_price_ids')) {
            Schema::table('plans', fn (Blueprint $table) => $table->json('legacy_stripe_price_ids')->nullable());
        }
        if (! Schema::hasColumn('subscriptions', 'starter_customer_id')) {
            Schema::table('subscriptions', fn (Blueprint $table) => $table->foreignId('starter_customer_id')->nullable()->constrained('customers')->nullOnDelete());
        }
        (new StarterPlanSeeder)->run();
    }

    public function down(): void
    {
        // Existing customers can be billed on either price. Keep their plan and
        // price history usable across application rollbacks.
    }
};
