<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->string('url');
            $table->string('title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('page_type')->default('general'); // product, money, general
            $table->json('metadata')->nullable(); // price, image, schema_org, etc.
            $table->longText('content')->nullable();
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->vector('embedding', 768)->nullable();
            } else {
                $table->json('embedding')->nullable();
            }
            $table->timestamps();

            $table->unique(['customer_id', 'url']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_pages');
    }
};
