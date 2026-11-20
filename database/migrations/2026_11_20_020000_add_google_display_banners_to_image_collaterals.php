<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_collaterals', fn (Blueprint $table) => $table->json('google_display_banners')->nullable());
    }

    public function down(): void
    {
        Schema::table('image_collaterals', fn (Blueprint $table) => $table->dropColumn('google_display_banners'));
    }
};
