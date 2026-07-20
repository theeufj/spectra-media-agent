<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class MccAccount extends Model
{
    protected $fillable = [
        'name',
        'google_customer_id',
        'refresh_token',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'refresh_token',
    ];

    /**
     * Scope to get only the active MCC account.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the currently active MCC account, falling back to env config.
     */
    public static function getActive(): ?self
    {
        $dbAccount = static::active()->first();

        if ($dbAccount) {
            return $dbAccount;
        }

        // Fall back to env config for backwards compatibility
        $envCustomerId = config('googleads.mcc_customer_id');
        $envRefreshToken = config('googleads.mcc_refresh_token');

        if ($envCustomerId && $envRefreshToken) {
            $account = new static();
            $account->name = 'Environment Default';
            $account->google_customer_id = $envCustomerId;
            $account->refresh_token = $envRefreshToken;
            $account->is_active = true;
            return $account;
        }

        return null;
    }

    /**
     * Get the refresh token in usable (plaintext) form.
     *
     * Persisted MCC tokens are encrypted at rest (the admin UI encrypts on save), so
     * every caller must decrypt before handing the token to Google. The env-fallback
     * instance built by getActive() is not persisted and holds a plaintext token.
     * Legacy plaintext rows (pre-encryption) are tolerated so a single un-migrated row
     * can't take down every server-side conversion upload. This is the single reader
     * that all conversion-upload paths must use. (DATA-2)
     */
    public function getDecryptedRefreshToken(): ?string
    {
        $token = $this->refresh_token;

        if (empty($token) || !$this->exists) {
            return $token ?: null;
        }

        try {
            return Crypt::decryptString($token);
        } catch (DecryptException $e) {
            // Row still holds a plaintext token — use it as-is.
            return $token;
        }
    }

    /**
     * Activate this MCC and deactivate all others.
     */
    public function activate(): void
    {
        static::where('id', '!=', $this->id)->update(['is_active' => false]);
        $this->update(['is_active' => true]);
    }
}
