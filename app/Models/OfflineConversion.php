<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineConversion extends Model
{
    protected $fillable = [
        'customer_id',
        'crm_integration_id',
        'gclid',
        'fbclid',
        'msclid',
        'crm_lead_id',
        'conversion_name',
        'conversion_value',
        'currency_code',
        'conversion_time',
        'upload_status',
        'upload_results',
        'crm_data',
    ];

    protected $casts = [
        'conversion_value' => 'decimal:2',
        'conversion_time' => 'datetime',
        'upload_results' => 'array',
        'crm_data' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function crmIntegration(): BelongsTo
    {
        return $this->belongsTo(CrmIntegration::class);
    }

    public function scopePending($query)
    {
        return $query->where('upload_status', 'pending');
    }

    public function scopeFailed($query)
    {
        return $query->where('upload_status', 'failed');
    }
}
