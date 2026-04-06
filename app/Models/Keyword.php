<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Keyword extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'campaign_id', 'ad_group_resource_name',
        'criterion_resource_name', 'keyword_text', 'match_type',
        'status', 'source', 'bid_micros', 'quality_score',
        'avg_monthly_searches', 'competition_index', 'estimated_cpc_micros',
        'ctr', 'conversions', 'cost', 'intent', 'cluster', 'funnel_stage',
        'added_by', 'added_by_agent',
    ];

    protected $casts = [
        'bid_micros' => 'integer',
        'quality_score' => 'integer',
        'avg_monthly_searches' => 'integer',
        'competition_index' => 'integer',
        'estimated_cpc_micros' => 'integer',
        'ctr' => 'float',
        'conversions' => 'float',
        'cost' => 'float',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    public function scopeByIntent($query, string $intent)
    {
        return $query->where('intent', $intent);
    }

    public function scopeDecliningQs($query)
    {
        return $query->whereNotNull('quality_score')->where('quality_score', '<', 5);
    }
}
