<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->uuid('generation_id')->nullable();
            $table->json('ad_extensions')->nullable();
            $table->json('conversion_goals')->nullable();
        });
        Schema::table('image_collaterals', function (Blueprint $table) {
            $table->json('generation_metadata')->nullable();
            $table->string('layout')->default('clean');
        });
        Schema::table('video_collaterals', function (Blueprint $table) {
            $table->json('generation_metadata')->nullable();
            $table->unsignedInteger('variation_index')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('strategies', fn (Blueprint $table) => $table->dropColumn(['generation_id', 'ad_extensions', 'conversion_goals']));
        Schema::table('image_collaterals', fn (Blueprint $table) => $table->dropColumn(['generation_metadata', 'layout']));
        Schema::table('video_collaterals', fn (Blueprint $table) => $table->dropColumn(['generation_metadata', 'variation_index']));
    }
};
