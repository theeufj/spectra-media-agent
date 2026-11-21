<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_account_readiness', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            $table->string('account_id', 80);
            $table->string('payer', 25);
            $table->char('currency', 3);
            $table->string('permissions', 30);
            $table->string('funding', 30);
            $table->string('tracking', 40);
            $table->string('blocker')->nullable();
            $table->string('owner', 32)->nullable();
            $table->text('next_action')->nullable();
            $table->timestampTz('checked_at');
            $table->unique(['customer_id', 'platform', 'account_id'], 'workspace_platform_account');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_account_readiness');
    }
};
