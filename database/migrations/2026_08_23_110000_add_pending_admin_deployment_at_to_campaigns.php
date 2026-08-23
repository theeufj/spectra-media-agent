<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a campaign entered the admin deployment queue. The user is
     * promised "within 24 hours"; without a timestamp nothing could measure
     * that promise, let alone re-alert on it.
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->timestamp('pending_admin_deployment_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('pending_admin_deployment_at');
        });
    }
};
