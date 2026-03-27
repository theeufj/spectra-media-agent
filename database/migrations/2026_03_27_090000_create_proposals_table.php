<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_name');
            $table->string('industry')->nullable();
            $table->string('website_url')->nullable();
            $table->decimal('budget', 10, 2)->nullable();
            $table->text('goals')->nullable();
            $table->json('platforms')->nullable();
            $table->string('status')->default('generating'); // generating, ready, failed
            $table->json('proposal_data')->nullable();
            $table->string('pdf_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
