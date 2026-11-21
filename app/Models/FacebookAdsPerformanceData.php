<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacebookAdsPerformanceData extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'strategy_id',
        'facebook_campaign_id',
        'facebook_account_id',
        'currency',
        'attribution_window',
        'attributed_conversions',
        'attributed_value',
        'date',
        'impressions',
        'clicks',
        'cost',
        'conversions',
        'conversion_value',
        'reach',
        'frequency',
        'cpc',
        'cpm',
        'cpa',
    ];

    protected $casts = [
        'date' => 'date',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'cost' => 'float',
        'conversions' => 'float',
        'conversion_value' => 'float',
        'reach' => 'integer',
        'frequency' => 'float',
        'cpc' => 'float',
        'cpm' => 'float',
        'cpa' => 'float',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
}
