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
}
