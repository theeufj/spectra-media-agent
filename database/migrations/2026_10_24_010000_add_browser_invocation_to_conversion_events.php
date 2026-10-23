<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spectra_conversion_events', function (Blueprint $table) {
            $table->timestampTz('browser_invoked_at')->nullable();
            $table->string('browser_target_resource')->nullable();
            $table->string('browser_binding_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('spectra_conversion_events', fn (Blueprint $table) => $table->dropColumn([
            'browser_invoked_at', 'browser_target_resource', 'browser_binding_fingerprint',
        ]));
    }
};
