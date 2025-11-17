<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // GTM container information
            $table->string('gtm_container_id')->nullable()->after('facebook_ads_access_token');
            $table->string('gtm_account_id')->nullable()->after('gtm_container_id');
            $table->string('gtm_workspace_id')->nullable()->after('gtm_account_id');
            
            // GTM configuration and status
            $table->json('gtm_config')->nullable()->after('gtm_workspace_id');
            $table->boolean('gtm_installed')->default(false)->after('gtm_config');
            $table->timestamp('gtm_last_verified')->nullable()->after('gtm_installed');
            
            // GTM detection tracking
            $table->boolean('gtm_detected')->default(false)->after('gtm_last_verified');
            $table->timestamp('gtm_detected_at')->nullable()->after('gtm_detected');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'gtm_container_id',
                'gtm_account_id',
                'gtm_workspace_id',
                'gtm_config',
                'gtm_installed',
                'gtm_last_verified',
                'gtm_detected',
                'gtm_detected_at',
            ]);
        });
    }
};
