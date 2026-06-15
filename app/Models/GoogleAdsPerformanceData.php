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
        'search_impression_share',
        'search_top_impression_share',
        'view_through_conversions',
        'all_conversions',
        'interaction_rate',
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
        'search_impression_share' => 'float',
        'search_top_impression_share' => 'float',
        'view_through_conversions' => 'float',
        'all_conversions' => 'float',
        'interaction_rate' => 'float',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
}
