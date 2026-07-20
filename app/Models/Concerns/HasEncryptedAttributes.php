<?php

namespace App\Models\Concerns;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts sensitive attributes at rest with a tolerant read path.
 *
 * Unlike the built-in `encrypted` cast, the getter falls back to the raw value when
 * decryption fails, so legacy plaintext rows written before encryption was introduced
 * keep working and can be migrated opportunistically. New/updated values are always
 * stored encrypted. All access must go through the model (no raw-SQL readers).
 */
trait HasEncryptedAttributes
{
    /**
     * Build an Attribute that encrypts on write and decrypts (tolerantly) on read.
     * Empty values pass through untouched.
     */
    protected function encryptedAttribute(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->decryptTolerantly($value),
            set: fn ($value) => ($value === null || $value === '')
                ? $value
                : Crypt::encryptString($value),
        );
    }

    /**
     * Decrypt a stored value, returning it unchanged if it is not encrypted
     * (legacy plaintext) or empty.
     */
    protected function decryptTolerantly($value)
    {
        if (empty($value)) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            return $value;
        }
    }
}
