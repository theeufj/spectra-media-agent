<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeywordQualityScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'campaign_google_id',
        'ad_group_resource_name',
        'criterion_resource_name',
        'keyword_text',
        'match_type',
        'quality_score',
        'creative_quality_score',
        'post_click_quality_score',
        'search_predicted_ctr',
        'impressions',
        'clicks',
        'conversions',
        'cost_micros',
        'cpc_bid_micros',
        'recorded_at',
    ];

    protected $casts = [
        'quality_score' => 'integer',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'conversions' => 'decimal:2',
        'cost_micros' => 'integer',
        'cpc_bid_micros' => 'integer',
        'recorded_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeForKeyword($query, string $text)
    {
        return $query->where('keyword_text', $text);
    }

    public function scopeTrending($query, int $days = 30)
    {
        return $query->where('recorded_at', '>=', now()->subDays($days))
            ->orderBy('recorded_at');
    }

    public function scopeDeclining($query, int $days = 14)
    {
        return $query->where('recorded_at', '>=', now()->subDays($days))
            ->whereNotNull('quality_score')
            ->where('quality_score', '<', 5);
    }
}
