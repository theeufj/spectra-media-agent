<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributionConversion extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'visitor_id',
        'conversion_type',
        'conversion_value',
        'touchpoints',
        'attributed_to',
    ];

    protected $casts = [
        'conversion_value' => 'decimal:2',
        'touchpoints' => 'array',
        'attributed_to' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Get attribution result for a specific model.
     */
    public function getAttributionFor(string $model): array
    {
        return $this->attributed_to[$model] ?? [];
    }
}
