<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_rankings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->string('keyword');
            $table->string('domain');
            $table->integer('position')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('search_engine')->default('google');
            $table->integer('previous_position')->nullable();
            $table->integer('change')->nullable();
            $table->date('date');
            $table->timestamps();

            $table->index(['customer_id', 'keyword', 'date']);
            $table->index(['customer_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_rankings');
    }
};
