<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // facebook_pixel_id already exists in production — only add missing column
            if (!Schema::hasColumn('customers', 'microsoft_uet_tag_id')) {
                $table->string('microsoft_uet_tag_id')->nullable()->after('microsoft_ads_account_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'microsoft_uet_tag_id')) {
                $table->dropColumn('microsoft_uet_tag_id');
            }
        });
    }
};
