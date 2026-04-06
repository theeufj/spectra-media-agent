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
        Schema::create('crm_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // hubspot, salesforce, zoho, pipedrive
            $table->json('credentials')->nullable(); // encrypted tokens
            $table->json('field_mappings')->nullable(); // CRM field → Spectra field
            $table->json('sync_settings')->nullable(); // frequency, filters, etc.
            $table->string('status')->default('disconnected'); // disconnected, connected, syncing, error
            $table->timestamp('last_synced_at')->nullable();
            $table->integer('total_leads_synced')->default(0);
            $table->integer('total_conversions_uploaded')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_integrations');
    }
};
