<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacebookAdsPerformanceData extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'facebook_campaign_id',
        'date',
        'impressions',
        'clicks',
        'cost',
        'conversions',
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
