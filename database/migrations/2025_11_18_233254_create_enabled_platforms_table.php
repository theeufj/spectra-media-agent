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
        Schema::create('enabled_platforms', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Platform name (e.g., 'Facebook', 'Google', 'Instagram')
            $table->string('slug')->unique(); // URL-friendly identifier
            $table->text('description')->nullable(); // Description of the platform
            $table->boolean('is_enabled')->default(true); // Whether the platform is enabled
            $table->integer('sort_order')->default(0); // For custom ordering
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enabled_platforms');
    }
};
