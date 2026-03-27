<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ABTest extends Model
{
    use HasFactory;

    protected $table = 'ab_tests';

    protected $fillable = [
        'strategy_id',
        'campaign_id',
        'test_type',
        'status',
        'variants',
        'started_at',
        'significance_reached_at',
        'confidence_level',
        'winning_variant_id',
        'results',
    ];

    protected $casts = [
        'variants' => 'array',
        'results' => 'array',
        'started_at' => 'datetime',
        'significance_reached_at' => 'datetime',
        'confidence_level' => 'float',
    ];

    const STATUS_RUNNING = 'running';
    const STATUS_SIGNIFICANT = 'significant';
    const STATUS_APPLIED = 'applied';
    const STATUS_STOPPED = 'stopped';

    const TYPE_HEADLINE = 'headline';
    const TYPE_DESCRIPTION = 'description';
    const TYPE_IMAGE = 'image';
    const TYPE_AUDIENCE = 'audience';

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function scopeRunning($query)
    {
        return $query->where('status', self::STATUS_RUNNING);
    }

    public function scopeForCampaign($query, int $campaignId)
    {
        return $query->where('campaign_id', $campaignId);
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function markSignificant(string $winningVariantId, float $confidence, array $results): void
    {
        $this->update([
            'status' => self::STATUS_SIGNIFICANT,
            'winning_variant_id' => $winningVariantId,
            'confidence_level' => $confidence,
            'significance_reached_at' => now(),
            'results' => $results,
        ]);
    }

    public function markApplied(): void
    {
        $this->update(['status' => self::STATUS_APPLIED]);
    }

    public function markStopped(): void
    {
        $this->update(['status' => self::STATUS_STOPPED]);
    }
}
