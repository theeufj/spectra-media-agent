<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * crm_integrations.credentials is cast `encrypted:array` on the model but the
 * column is `json`. An encrypted cast emits an opaque ciphertext string, which
 * Postgres rejects:
 *
 *   SQLSTATE[22P02]: invalid input syntax for type json
 *
 * So saving a CRM integration has always failed. That — not lack of interest —
 * is why the table has zero rows: configuring one was impossible.
 *
 * The migration that created it already intended encryption ("// encrypted
 * tokens"); only the column type was wrong. Widening to text is what the cast
 * needs. field_mappings and sync_settings are plain `array` casts and stay json.
 *
 * The table is empty in production, so there is nothing to convert.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ALTER ... TYPE text is safe from json in Postgres, and the table is empty.
        DB::statement('ALTER TABLE crm_integrations ALTER COLUMN credentials TYPE text USING credentials::text');
    }

    public function down(): void
    {
        // Only reversible while the column holds no ciphertext.
        if (DB::table('crm_integrations')->whereNotNull('credentials')->exists()) {
            throw new RuntimeException(
                'Refusing to revert: crm_integrations.credentials holds data that is not valid JSON once encrypted.'
            );
        }

        DB::statement('ALTER TABLE crm_integrations ALTER COLUMN credentials TYPE json USING credentials::json');
    }
};
