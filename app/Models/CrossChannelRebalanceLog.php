<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrossChannelRebalanceLog extends Model
{
    protected $fillable = [
        'customer_id',
        'before_allocation',
        'after_allocation',
        'performance_snapshot',
        'recommendations',
        'trigger',
        'auto_executed',
        'estimated_improvement_pct',
    ];

    protected $casts = [
        'before_allocation' => 'array',
        'after_allocation' => 'array',
        'performance_snapshot' => 'array',
        'recommendations' => 'array',
        'auto_executed' => 'boolean',
        'estimated_improvement_pct' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
