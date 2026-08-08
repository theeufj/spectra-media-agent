<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MicrosoftAdsPerformanceData extends Model
{
    protected $table = 'microsoft_ads_performance_data';

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
        // Money is stored as decimal (see the 2026_08_08 migration) so that
        // SUM() in Postgres is exact — that is where the precision mattered,
        // because AdSpendBillingService bills on the summed cost.
        //
        // The PHP cast stays float to match GoogleAds/FacebookAds/LinkedInAds
        // performance models: a `decimal:` cast returns a string, and
        // RoiDashboardController serialises all four platforms into one payload,
        // so Microsoft alone emitting "12.34" instead of 12.34 would break it.
        'cost' => 'float',
        'conversions' => 'float',
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
