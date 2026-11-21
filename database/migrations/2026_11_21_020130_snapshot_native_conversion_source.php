<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spectra_conversion_events', fn (Blueprint $table) => $table->json('verified_source')->nullable());
    }

    public function down(): void
    {
        Schema::table('spectra_conversion_events', fn (Blueprint $table) => $table->dropColumn('verified_source'));
    }
};
