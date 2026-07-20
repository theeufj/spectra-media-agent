<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmIntegration extends Model
{
    protected $fillable = [
        'customer_id',
        'provider',
        'credentials',
        'field_mappings',
        'sync_settings',
        'status',
        'last_synced_at',
        'total_leads_synced',
        'total_conversions_uploaded',
        'last_error',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'field_mappings' => 'array',
        'sync_settings' => 'array',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = ['credentials'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function offlineConversions(): HasMany
    {
        return $this->hasMany(OfflineConversion::class);
    }

    public function isConnected(): bool
    {
        return in_array($this->status, ['connected', 'syncing']);
    }

    /**
     * Whether the daily scheduler should still attempt to sync this integration.
     * Includes 'error' so a transient failure self-heals on the next run instead of
     * disabling the integration forever, and 'syncing' so a crash-stranded run is
     * retried. Excludes a deliberate 'disconnected'.
     */
    public function isSyncable(): bool
    {
        return in_array($this->status, ['connected', 'syncing', 'error']);
    }
}
