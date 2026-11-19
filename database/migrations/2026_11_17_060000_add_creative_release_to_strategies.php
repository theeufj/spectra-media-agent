<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->json('creative_release_review')->nullable();
            $table->json('creative_approval')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->dropColumn(['creative_release_review', 'creative_approval']);
        });
    }
};
