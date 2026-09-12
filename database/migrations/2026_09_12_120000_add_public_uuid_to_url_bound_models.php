<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Non-enumerable identifiers for the models that appear in URLs.
 *
 * These four carry 97 of the 142 route-model bindings. The primary keys are
 * untouched — see App\Models\Concerns\HasPublicUuid for why a UUID primary key
 * would be the expensive way to buy the same thing.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['customers', 'campaigns', 'strategies', 'users'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->uuid('uuid')->nullable()->after('id');
            });

            // Backfill in chunks rather than one statement per row: these are
            // small tables today, but a migration that is fine at 23 customers
            // and locks the table at 23,000 is a trap left for later.
            DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(['uuid' => (string) Str::uuid7()]);
                }
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unique('uuid');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropUnique($table.'_uuid_unique');
                $blueprint->dropColumn('uuid');
            });
        }
    }
};
