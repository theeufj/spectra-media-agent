<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track the state of a link invitation to a customer's existing Google Ads
 * account.
 *
 * Google will not let a manager account create client accounts through the API
 * until it has managed roughly US$1,000 of spend. Linking an account the
 * customer already owns carries no such threshold — the manager sends an
 * invitation, the customer accepts it in their own Google Ads UI, and from then
 * on it is managed exactly like a created sub-account.
 *
 * That makes existing-account customers onboardable now rather than after the
 * gate clears, so the invitation needs a state of its own: sent, accepted, or
 * refused. Without it, "we asked and they have not answered yet" is
 * indistinguishable from "we never asked".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // pending | active | refused | cancelled
            $table->string('google_ads_link_status')->nullable()->after('google_ads_customer_id');
            $table->timestamp('google_ads_link_requested_at')->nullable()->after('google_ads_link_status');
            $table->timestamp('google_ads_link_confirmed_at')->nullable()->after('google_ads_link_requested_at');

            // Resource name of the pending link, so its status can be polled
            // without guessing which invitation belongs to this customer.
            $table->string('google_ads_link_resource_name')->nullable()->after('google_ads_link_confirmed_at');

            $table->index('google_ads_link_status');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['google_ads_link_status']);
            $table->dropColumn([
                'google_ads_link_status',
                'google_ads_link_requested_at',
                'google_ads_link_confirmed_at',
                'google_ads_link_resource_name',
            ]);
        });
    }
};
