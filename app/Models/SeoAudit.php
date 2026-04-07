<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'url',
        'score',
        'issues',
        'recommendations',
        'meta_analysis',
        'heading_analysis',
        'image_analysis',
        'link_analysis',
        'schema_analysis',
        'security_analysis',
        'performance_analysis',
        'content_analysis',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'issues' => 'array',
            'recommendations' => 'array',
            'meta_analysis' => 'array',
            'heading_analysis' => 'array',
            'image_analysis' => 'array',
            'link_analysis' => 'array',
            'schema_analysis' => 'array',
            'security_analysis' => 'array',
            'performance_analysis' => 'array',
            'content_analysis' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function passed(): bool
    {
        return $this->score >= 70;
    }

    public function criticalIssues(): array
    {
        return collect($this->issues ?? [])->where('severity', 'critical')->values()->toArray();
    }
}
