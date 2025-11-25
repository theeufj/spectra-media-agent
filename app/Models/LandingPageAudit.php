<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingPageAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'url',
        'load_time_ms',
        'page_size_kb',
        'dom_elements',
        'core_web_vitals',
        'has_above_fold_cta',
        'cta_buttons',
        'cta_count',
        'primary_cta',
        'message_match_score',
        'message_analysis',
        'keywords_found',
        'issues',
        'recommendations',
        'overall_score',
        'audited_at',
    ];

    protected $casts = [
        'core_web_vitals' => 'array',
        'cta_buttons' => 'array',
        'keywords_found' => 'array',
        'issues' => 'array',
        'recommendations' => 'array',
        'has_above_fold_cta' => 'boolean',
        'audited_at' => 'datetime',
    ];

    /**
     * Get the customer that owns the audit.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Check if the page passed the audit (score >= 70).
     */
    public function passed(): bool
    {
        return $this->overall_score >= 70;
    }

    /**
     * Get critical issues (issues that significantly impact conversion).
     */
    public function criticalIssues(): array
    {
        if (!$this->issues) {
            return [];
        }

        return array_filter($this->issues, function ($issue) {
            return isset($issue['severity']) && $issue['severity'] === 'critical';
        });
    }

    /**
     * Get high-priority recommendations.
     */
    public function priorityRecommendations(): array
    {
        if (!$this->recommendations) {
            return [];
        }

        return array_filter($this->recommendations, function ($rec) {
            return isset($rec['priority']) && in_array($rec['priority'], ['critical', 'high']);
        });
    }
}
