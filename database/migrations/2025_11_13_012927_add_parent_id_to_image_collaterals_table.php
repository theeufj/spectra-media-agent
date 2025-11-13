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
        Schema::table('image_collaterals', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->constrained('image_collaterals')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('image_collaterals', function (Blueprint $table) {
            //
        });
    }
};
