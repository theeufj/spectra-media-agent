<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CampaignHourlyPerformance extends Model
{
    use HasFactory;

    protected $table = 'campaign_hourly_performance';

    protected $fillable = [
        'campaign_id',
        'customer_id',
        'date',
        'hour',
        'day_of_week',
        'platform',
        'impressions',
        'clicks',
        'conversions',
        'spend',
        'conversion_value',
        'ctr',
        'roas',
    ];

    protected $casts = [
        'date' => 'date',
        'hour' => 'integer',
        'day_of_week' => 'integer',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'conversions' => 'float',
        'spend' => 'float',
        'conversion_value' => 'float',
        'ctr' => 'float',
        'roas' => 'float',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Compute learned hourly multipliers for a customer based on historical ROAS.
     * Returns an array keyed by hour (0-23) with multiplier values.
     * Falls back to null if insufficient data (< 14 days).
     */
    public static function getLearnedHourlyMultipliers(int $customerId, int $minDays = 14): ?array
    {
        $dataPoints = static::where('customer_id', $customerId)
            ->where('date', '>=', now()->subDays(60))
            ->selectRaw('hour, AVG(roas) as avg_roas, SUM(impressions) as total_impressions, COUNT(DISTINCT date) as days_count')
            ->groupBy('hour')
            ->having('days_count', '>=', $minDays)
            ->having('total_impressions', '>', 0)
            ->get();

        if ($dataPoints->isEmpty() || $dataPoints->count() < 12) {
            return null; // Not enough hourly coverage
        }

        $overallAvgRoas = $dataPoints->avg('avg_roas');
        if ($overallAvgRoas <= 0) {
            return null;
        }

        $multipliers = [];
        foreach ($dataPoints as $point) {
            // Normalize: hours with above-average ROAS get > 1.0 multiplier
            $rawMultiplier = $point->avg_roas / $overallAvgRoas;
            // Clamp between 0.3x and 2.0x to avoid extreme swings
            $multipliers[$point->hour] = max(0.3, min(2.0, round($rawMultiplier, 2)));
        }

        return $multipliers;
    }

    /**
     * Compute learned day-of-week multipliers for a customer.
     * Returns an array keyed by day (0=Sunday..6=Saturday).
     */
    public static function getLearnedDayMultipliers(int $customerId, int $minWeeks = 3): ?array
    {
        $dataPoints = static::where('customer_id', $customerId)
            ->where('date', '>=', now()->subDays(60))
            ->selectRaw('day_of_week, AVG(roas) as avg_roas, SUM(impressions) as total_impressions, COUNT(DISTINCT date) as days_count')
            ->groupBy('day_of_week')
            ->having('days_count', '>=', $minWeeks)
            ->having('total_impressions', '>', 0)
            ->get();

        if ($dataPoints->isEmpty() || $dataPoints->count() < 5) {
            return null;
        }

        $overallAvgRoas = $dataPoints->avg('avg_roas');
        if ($overallAvgRoas <= 0) {
            return null;
        }

        $dayNames = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $multipliers = [];
        foreach ($dataPoints as $point) {
            $rawMultiplier = $point->avg_roas / $overallAvgRoas;
            $multipliers[$dayNames[$point->day_of_week]] = max(0.3, min(2.0, round($rawMultiplier, 2)));
        }

        return $multipliers;
    }
}
