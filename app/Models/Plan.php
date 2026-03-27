<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_cents',
        'billing_interval',
        'stripe_price_id',
        'features',
        'is_active',
        'is_free',
        'sort_order',
    ];

    protected $casts = [
        'features' => 'array',
        'is_active' => 'boolean',
        'is_free' => 'boolean',
        'price_cents' => 'integer',
        'sort_order' => 'integer',
    ];

    protected $appends = ['formatted_price'];

    public function getFormattedPriceAttribute(): string
    {
        if ($this->is_free) {
            return '$0 / month';
        }

        $dollars = number_format($this->price_cents / 100, 0);
        return "\${$dollars} / {$this->billing_interval}";
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('price_cents');
    }
}
