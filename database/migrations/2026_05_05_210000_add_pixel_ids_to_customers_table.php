<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('facebook_pixel_id')->nullable()->after('facebook_bm_owned');
            $table->string('microsoft_uet_tag_id')->nullable()->after('microsoft_ads_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['facebook_pixel_id', 'microsoft_uet_tag_id']);
        });
    }
};
