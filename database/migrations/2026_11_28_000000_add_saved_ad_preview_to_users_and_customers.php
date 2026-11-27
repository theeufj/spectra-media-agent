<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->jsonb('saved_ad_preview')->nullable());
        Schema::table('customers', fn (Blueprint $table) => $table->jsonb('saved_ad_preview')->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('saved_ad_preview'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('saved_ad_preview'));
    }
};
