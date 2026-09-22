<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Recommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'type',
        'target_entity',
        'parameters',
        'rationale',
        'status',
        'requires_approval',
        'platform', 'source', 'fingerprint', 'evidence', 'payload', 'execution', 'outcome',
        'applied_at', 'verified_at', 'measured_at',
    ];

    protected $casts = [
        'target_entity' => 'array',
        'parameters' => 'array',
        'requires_approval' => 'boolean',
        'evidence' => 'array', 'payload' => 'array', 'execution' => 'array', 'outcome' => 'array',
        'applied_at' => 'datetime', 'verified_at' => 'datetime', 'measured_at' => 'datetime',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Campaign, $this> */
    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Is this exact suggestion already sitting in the queue unanswered?
     *
     * The optimiser runs nightly over every campaign and re-derives its
     * recommendations from the same performance data, so an account nobody has
     * triaged in a fortnight accumulated fourteen copies of "pause this ad
     * group" — and the list where a human is supposed to make decisions became
     * the list they scroll past.
     *
     * Scoped to pending on purpose. A pending recommendation is a queue
     * awaiting an answer and a second copy adds nothing; an applied or failed
     * one is a record of something that happened, and collapsing history would
     * hide that the same fix was needed twice.
     *
     * @param  array<string, mixed>|null  $target
     */
    public static function alreadyPending(int $campaignId, string $type, ?array $target): bool
    {
        return static::where('campaign_id', $campaignId)
            ->where('type', $type)
            ->where('status', 'pending')
            ->get(['target_entity'])
            // Compared in PHP rather than as JSON in SQL: the cast hands back
            // an array whose key order is not guaranteed, so two identical
            // targets can serialise to different strings.
            ->contains(fn ($existing) => self::sameTarget($existing->target_entity, $target));
    }

    /** @param array<string, mixed>|null $a @param array<string, mixed>|null $b */
    private static function sameTarget(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        ksort($a);
        ksort($b);

        return $a == $b;
    }
}
