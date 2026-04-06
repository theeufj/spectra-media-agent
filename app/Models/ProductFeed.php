<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductFeed extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'merchant_id',
        'feed_name',
        'source_type',
        'source_url',
        'source_config',
        'status',
        'total_products',
        'approved_products',
        'disapproved_products',
        'last_synced_at',
        'sync_frequency',
        'last_error',
        'feed_diagnostics',
    ];

    protected $casts = [
        'source_config' => 'array',
        'feed_diagnostics' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function getHealthScoreAttribute(): int
    {
        if ($this->total_products === 0) return 0;
        return (int) round(($this->approved_products / $this->total_products) * 100);
    }
}
