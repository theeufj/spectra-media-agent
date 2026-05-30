<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_collaterals', function (Blueprint $table) {
            $table->unsignedBigInteger('strategy_id')->nullable()->change();
        });

        Schema::table('video_collaterals', function (Blueprint $table) {
            $table->unsignedBigInteger('strategy_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('image_collaterals', function (Blueprint $table) {
            $table->unsignedBigInteger('strategy_id')->nullable(false)->change();
        });

        Schema::table('video_collaterals', function (Blueprint $table) {
            $table->unsignedBigInteger('strategy_id')->nullable(false)->change();
        });
    }
};
