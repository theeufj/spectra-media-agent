<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HarvestedAsset extends Model
{
    protected $fillable = [
        'customer_id',
        'customer_page_id',
        'source_url',
        'source_page_url',
        's3_path',
        'cloudfront_url',
        'original_width',
        'original_height',
        'mime_type',
        'file_size',
        'classification',
        'classification_details',
        'status',
        'variants',
        'bg_removed_s3_path',
        'bg_removed_url',
    ];

    protected $casts = [
        'classification_details' => 'array',
        'variants' => 'array',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerPage()
    {
        return $this->belongsTo(CustomerPage::class);
    }

    public function scopeUsable($query)
    {
        return $query->whereIn('classification', ['product', 'lifestyle', 'team'])
            ->where('status', 'processed');
    }

    public function scopeProducts($query)
    {
        return $query->where('classification', 'product');
    }

    public function isUsableForAds(): bool
    {
        return in_array($this->classification, ['product', 'lifestyle', 'team'])
            && $this->status === 'processed';
    }
}
