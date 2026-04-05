<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('campaign_id')->nullable()->constrained()->onDelete('set null');
            $table->string('name');
            $table->enum('platform', ['google', 'facebook']);
            $table->enum('type', ['customer_match', 'remarketing', 'combined', 'lookalike']);
            $table->string('platform_audience_id')->nullable();
            $table->string('platform_resource_name')->nullable();
            $table->integer('estimated_size')->nullable();
            $table->enum('status', ['creating', 'active', 'closed', 'error'])->default('creating');
            $table->json('source_data')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'platform', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiences');
    }
};
