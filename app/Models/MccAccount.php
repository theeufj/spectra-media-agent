<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
     * Activate this MCC and deactivate all others.
     */
    public function activate(): void
    {
        static::where('id', '!=', $this->id)->update(['is_active' => false]);
        $this->update(['is_active' => true]);
    }
}
