<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('conversion_action_id')->nullable()->after('google_ads_customer_id');
            $table->string('conversion_action_label')->nullable()->after('conversion_action_id');
            $table->timestamp('conversion_tracking_verified_at')->nullable()->after('conversion_action_label');
            $table->string('facebook_pixel_id')->nullable()->after('conversion_tracking_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'conversion_action_id',
                'conversion_action_label',
                'conversion_tracking_verified_at',
                'facebook_pixel_id',
            ]);
        });
    }
};
