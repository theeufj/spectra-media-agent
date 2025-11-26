<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Competitor extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'url',
        'domain',
        'name',
        'title',
        'meta_description',
        'headings',
        'raw_content',
        'messaging_analysis',
        'value_propositions',
        'keywords_detected',
        'pricing_info',
        'ad_copy_samples',
        'auction_insights',
        'impression_share',
        'overlap_rate',
        'position_above_rate',
        'last_analyzed_at',
        'discovery_source',
    ];

    protected $casts = [
        'headings' => 'array',
        'messaging_analysis' => 'array',
        'value_propositions' => 'array',
        'keywords_detected' => 'array',
        'pricing_info' => 'array',
        'ad_copy_samples' => 'array',
        'auction_insights' => 'array',
        'impression_share' => 'decimal:2',
        'overlap_rate' => 'decimal:2',
        'position_above_rate' => 'decimal:2',
        'last_analyzed_at' => 'datetime',
    ];

    /**
     * Get the customer that owns this competitor record.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Extract the domain from the URL.
     */
    public static function extractDomain(string $url): string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? $url;
        
        // Remove www. prefix
        return preg_replace('/^www\./', '', $host);
    }

    /**
     * Scope to get competitors by discovery source.
     */
    public function scopeBySource($query, string $source)
    {
        return $query->where('discovery_source', $source);
    }

    /**
     * Scope to get competitors that need analysis refresh.
     */
    public function scopeNeedsAnalysis($query, int $daysOld = 7)
    {
        return $query->where(function ($q) use ($daysOld) {
            $q->whereNull('last_analyzed_at')
              ->orWhere('last_analyzed_at', '<', now()->subDays($daysOld));
        });
    }
}
