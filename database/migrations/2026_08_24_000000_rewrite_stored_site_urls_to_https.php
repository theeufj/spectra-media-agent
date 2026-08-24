<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Customer::setWebsiteAttribute now forces https on write; this brings
     * pre-existing rows in line so CrawlSitemap (which preserves an explicit
     * scheme) never fetches a customer site over plain http again.
     */
    public function up(): void
    {
        DB::table('customers')
            ->where('website', 'like', 'http://%')
            ->update(['website' => DB::raw("'https://' || substring(website from 8)")]);

        DB::table('proposals')
            ->where('website_url', 'like', 'http://%')
            ->update(['website_url' => DB::raw("'https://' || substring(website_url from 8)")]);
    }

    public function down(): void
    {
        // Intentionally irreversible — the original scheme is not worth keeping.
    }
};
