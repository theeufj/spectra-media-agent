<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCost extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'customer_id',
        'service',
        'operation',
        'model',
        'input_tokens',
        'output_tokens',
        'cached_tokens',
        'cost',
        'duration_ms',
        'task_type',
        'metadata',
    ];

    protected $casts = [
        'cost'     => 'decimal:6',
        'metadata' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public static function totalCostForCustomer(int $customerId, ?string $since = null): float
    {
        return (float) static::where('customer_id', $customerId)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->sum('cost');
    }

    public static function totalCostForCampaign(int $campaignId): float
    {
        return (float) static::where('campaign_id', $campaignId)->sum('cost');
    }
}
