<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = [
        'product_feed_id',
        'customer_id',
        'offer_id',
        'title',
        'description',
        'link',
        'image_link',
        'price',
        'sale_price',
        'currency_code',
        'availability',
        'condition',
        'brand',
        'gtin',
        'mpn',
        'google_product_category',
        'product_type',
        'status',
        'disapproval_reasons',
        'custom_attributes',
        'impressions',
        'clicks',
        'cost',
        'conversions',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'disapproval_reasons' => 'array',
        'custom_attributes' => 'array',
        'impressions' => 'float',
        'clicks' => 'float',
        'cost' => 'float',
        'conversions' => 'float',
    ];

    public function productFeed(): BelongsTo
    {
        return $this->belongsTo(ProductFeed::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeDisapproved($query)
    {
        return $query->where('status', 'disapproved');
    }

    public function scopeInStock($query)
    {
        return $query->where('availability', 'in_stock');
    }
}
