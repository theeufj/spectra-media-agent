<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clean up any lingering per-customer LinkedIn OAuth token columns.
 *
 * The management account pattern requires all LinkedIn API calls to use
 * the platform-level refresh token from config/linkedinads.php.
 * Per-customer tokens are prohibited (see config/platform_architecture.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $columns = [];
            foreach (['linkedin_oauth_access_token', 'linkedin_oauth_refresh_token', 'linkedin_token_expires_at'] as $col) {
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
