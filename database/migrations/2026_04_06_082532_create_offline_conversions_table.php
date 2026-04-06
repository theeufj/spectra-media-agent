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
        Schema::create('offline_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gclid')->nullable(); // Google click ID
            $table->string('fbclid')->nullable(); // Facebook click ID
            $table->string('msclid')->nullable(); // Microsoft click ID
            $table->string('crm_lead_id')->nullable();
            $table->string('conversion_name');
            $table->decimal('conversion_value', 12, 2)->nullable();
            $table->string('currency_code', 3)->default('USD');
            $table->timestamp('conversion_time');
            $table->string('upload_status')->default('pending'); // pending, uploaded_google, uploaded_facebook, uploaded_all, failed
            $table->json('upload_results')->nullable();
            $table->json('crm_data')->nullable(); // raw CRM record data
            $table->timestamps();

            $table->index(['customer_id', 'upload_status']);
            $table->index('gclid');
            $table->index('fbclid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offline_conversions');
    }
};
