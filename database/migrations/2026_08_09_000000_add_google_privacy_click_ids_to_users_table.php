<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google does not always send a gclid.
 *
 * On iOS, where ATT prevents the usual click identifier, Google Ads auto-tagging
 * substitutes `wbraid` (web-to-web) or `gbraid` (app-to-web). We stored neither,
 * so those visits carried no identifier at all and their conversions could never
 * be uploaded — invisible to Smart Bidding no matter what else was fixed.
 *
 * That is not a marginal case here: 99% of clicks on the live campaign are
 * mobile or tablet (77% mobile, 22% tablet, 1% desktop).
 *
 * CanonicalRedirect already preserved both params through its allowlist; only
 * the capture and storage side was missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('gbraid')->nullable()->after('gclid');
            $table->string('wbraid')->nullable()->after('gbraid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['gbraid', 'wbraid']);
        });
    }
};
