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
            // Path A: marks that this ad account was created by the platform's
            // Business Manager — no per-client OAuth token is needed.
            $table->boolean('facebook_bm_owned')->default(false)->after('facebook_token_is_long_lived');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('facebook_bm_owned');
        });
    }
};
