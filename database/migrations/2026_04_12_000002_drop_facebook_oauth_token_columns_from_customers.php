<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove per-customer Facebook OAuth token columns.
 *
 * The management account pattern requires all Facebook API calls to use
 * the platform System User token from config('services.facebook.system_user_token').
 * Per-customer tokens are prohibited (see config/platform_architecture.php).
 *
 * Kept: facebook_ads_account_id, facebook_page_id, facebook_page_name, facebook_bm_owned
 * (these are account identifiers, not credentials)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $columns = [];
            foreach ([
                'facebook_ads_access_token',
                'facebook_token_expires_at',
                'facebook_token_refreshed_at',
                'facebook_token_is_long_lived',
            ] as $col) {
                if (Schema::hasColumn('customers', $col)) {
                    $columns[] = $col;
                }
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }

    public function down(): void
    {
        // Not re-adding — these columns violate the management account pattern.
    }
};
