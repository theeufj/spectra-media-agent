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
            $table->timestamp('facebook_token_expires_at')->nullable()->after('facebook_ads_access_token');
            $table->timestamp('facebook_token_refreshed_at')->nullable()->after('facebook_token_expires_at');
            $table->boolean('facebook_token_is_long_lived')->default(false)->after('facebook_token_refreshed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'facebook_token_expires_at',
                'facebook_token_refreshed_at',
                'facebook_token_is_long_lived',
            ]);
        });
    }
};
