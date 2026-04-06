<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkedInAdsPerformanceData extends Model
{
    protected $table = 'linkedin_ads_performance_data';

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
        'cost' => 'float',
        'conversion_value' => 'float',
        'ctr' => 'float',
        'cpc' => 'float',
        'cpa' => 'float',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
