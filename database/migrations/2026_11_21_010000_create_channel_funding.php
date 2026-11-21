<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // A grandfathered relationship is bound to the actual provider account.
            $table->string('grandfathered_google_payer_account')->nullable();
        });
        DB::table('customers')->where('google_ads_link_status', 'active')->whereNotNull('google_ads_link_confirmed_at')
            ->whereNotNull('google_ads_link_requested_at')->whereNotNull('google_ads_customer_id')->whereNotNull('google_ads_link_resource_name')
            ->orderBy('id')->chunkById(100, function ($customers) {
                foreach ($customers as $customer) {
                    $account = str_replace('-', '', $customer->google_ads_customer_id);
                    $manager = str_replace('-', '', (string) $customer->google_ads_manager_customer_id);
                    $history = DB::table('agent_activities')->where('customer_id', $customer->id)->where('agent_type', 'onboarding')
                        ->where('status', 'completed')->where('details->client_account', $account);
                    if (ctype_digit($account) && ctype_digit($manager) && $account !== $manager
                        && (clone $history)->where('details->manager_account', $manager)->where('action', 'google_ads_account_linked')->exists()
                        && ! (clone $history)->where('action', 'google_ads_account_created')->exists()) {
                        DB::table('customers')->where('id', $customer->id)->update(['grandfathered_google_payer_account' => $account]);
                    }
                }
            });
        Schema::create('launch_funding_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('strategy_id')->constrained()->restrictOnDelete();
            $table->uuid('launch_id')->unique();
            $table->foreignId('payer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('platform');
            $table->string('account_id');
            $table->string('currency', 3);
            $table->string('payer');
            $table->unsignedBigInteger('target_cents');
            $table->unsignedBigInteger('held_cents');
            $table->unsignedBigInteger('charge_cents');
            $table->string('status');
            $table->string('payment_intent_id')->nullable()->unique();
            $table->index(['customer_id', 'currency', 'status']);
            $table->timestamps();
        });
        Schema::create('launch_funding_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('launch_funding_reservations')->restrictOnDelete();
            $table->string('resource_id');
            $table->date('spend_date');
            $table->unsignedBigInteger('observed_cents');
            $table->unsignedBigInteger('consumed_cents');
            $table->unique(['reservation_id', 'resource_id', 'spend_date'], 'launch_spend_day');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('launch_funding_settlements');
        Schema::dropIfExists('launch_funding_reservations');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('grandfathered_google_payer_account'));
    }
};
