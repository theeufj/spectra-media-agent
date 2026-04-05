<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Audience extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'campaign_id',
        'name',
        'platform',
        'type',
        'platform_audience_id',
        'platform_resource_name',
        'estimated_size',
        'status',
        'source_data',
        'error_message',
    ];

    protected $casts = [
        'source_data' => 'array',
        'estimated_size' => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
}
