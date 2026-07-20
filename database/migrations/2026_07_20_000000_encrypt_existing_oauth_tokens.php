<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypt OAuth tokens / secrets that were stored in plaintext before the
 * HasEncryptedAttributes trait was introduced (DATA-6).
 *
 * Operates on raw column values (bypassing the model mutators) and is idempotent:
 * a value that already decrypts is left untouched, so a partial run or a re-run
 * cannot double-encrypt. Nothing here is destructive — the tolerant getter keeps
 * reading plaintext rows correctly whether or not this migration has run.
 */
return new class extends Migration
{
    private array $targets = [
        'connections' => ['access_token', 'refresh_token'],
        'customers'   => ['tracking_signing_secret', 'google_ads_refresh_token'],
    ];

    public function up(): void
    {
        // An encrypted value is far longer than the plaintext it replaces (a 64-char
        // secret becomes ~250 chars of base64). Widen any narrow columns to TEXT first
        // so the encrypted values don't overflow. tracking_signing_secret is varchar(64);
        // the other target columns are already TEXT.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customers ALTER COLUMN tracking_signing_secret TYPE text');
        } else {
            Schema::table('customers', function (Blueprint $table) {
                $table->text('tracking_signing_secret')->nullable()->change();
            });
        }

        foreach ($this->targets as $table => $columns) {
            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $columns) {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach ($columns as $column) {
                        $value = $row->{$column} ?? null;
                        if ($value === null || $value === '') {
                            continue;
                        }

                        // Already encrypted? Leave it.
                        try {
                            Crypt::decryptString($value);
                            continue;
                        } catch (DecryptException $e) {
                            // Plaintext — encrypt it below.
                        }

                        $updates[$column] = Crypt::encryptString($value);
                    }

                    if (!empty($updates)) {
                        DB::table($table)->where('id', $row->id)->update($updates);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Irreversible by design — we do not restore plaintext secrets.
    }
};
