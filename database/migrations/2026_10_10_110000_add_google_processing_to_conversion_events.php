<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spectra_conversion_events', function (Blueprint $table) {
            $table->string('google_conversion_resource')->nullable();
            $table->timestampTz('google_accepted_at')->nullable();
            $table->string('google_processing_status')->nullable();
            $table->timestampTz('google_processing_checked_at')->nullable();
            $table->unsignedInteger('google_processing_attempts')->default(0);
            $table->json('google_processing_details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('spectra_conversion_events', function (Blueprint $table) {
            $table->dropColumn(['google_conversion_resource', 'google_accepted_at', 'google_processing_status',
                'google_processing_checked_at', 'google_processing_attempts', 'google_processing_details']);
        });
    }
};
