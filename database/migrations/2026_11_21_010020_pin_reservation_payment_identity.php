<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('launch_funding_reservations', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable();
            $table->unsignedBigInteger('refunded_cents')->default(0);
            $table->string('refund_charge_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('launch_funding_reservations', fn (Blueprint $table) => $table->dropColumn(['stripe_customer_id', 'refunded_cents', 'refund_charge_id']));
    }
};
