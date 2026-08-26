<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which vertical skin a customer belongs to. Users have carried this
     * since the skins launched, but customer-scoped email (every lifecycle
     * notification) had no way to know which brand to speak as — so every
     * realpropertyads customer got Site to Spend emails.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('tenant_key')->nullable()->after('website');
        });

        // Backfill from the earliest-attached user who has a tenant.
        DB::statement(<<<'SQL'
            UPDATE customers c
            SET tenant_key = sub.tenant_key
            FROM (
                SELECT DISTINCT ON (cu.customer_id) cu.customer_id, u.tenant_key
                FROM customer_user cu
                JOIN users u ON u.id = cu.user_id
                WHERE u.tenant_key IS NOT NULL
                ORDER BY cu.customer_id, cu.created_at ASC
            ) sub
            WHERE c.id = sub.customer_id AND c.tenant_key IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('tenant_key');
        });
    }
};
