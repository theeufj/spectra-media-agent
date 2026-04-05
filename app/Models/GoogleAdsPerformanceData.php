<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoogleAdsPerformanceData extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'date',
        'impressions',
        'clicks',
        'cost',
        'conversions',
        'conversion_value',
        'ctr',
        'cpc',
        'cpa',
    ];

    protected $casts = [
        'date' => 'date',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'cost' => 'float',
        'conversions' => 'float',
        'conversion_value' => 'float',
        'ctr' => 'float',
        'cpc' => 'float',
        'cpa' => 'float',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
}
