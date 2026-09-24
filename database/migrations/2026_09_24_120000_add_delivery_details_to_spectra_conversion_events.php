<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spectra_conversion_events', function (Blueprint $table) {
            $table->string('deduplication_key')->nullable()->unique();
            $table->json('ad_identifiers')->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->string('google_request_id')->nullable();
            $table->text('upload_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('spectra_conversion_events', function (Blueprint $table) {
            $table->dropColumn(['deduplication_key', 'ad_identifiers', 'occurred_at', 'google_request_id', 'upload_error']);
        });
    }
};
