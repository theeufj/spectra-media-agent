<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentActivity extends Model
{
    use BelongsToCustomer;
    use Prunable;

    protected $fillable = [
        'customer_id',
        'campaign_id',
        'agent_type',
        'action',
        'description',
        'details',
        'status',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Log an agent activity.
     */
    public static function record(
        string $agentType,
        string $action,
        string $description,
        ?int $customerId = null,
        ?int $campaignId = null,
        array $details = [],
        string $status = 'completed'
    ): self {
        return self::create([
            'customer_id' => $customerId,
            'campaign_id' => $campaignId,
            'agent_type' => $agentType,
            'action' => $action,
            'description' => $description,
            'details' => $details ?: null,
            'status' => $status,
        ]);
    }

    /**
     * Records eligible for `model:prune` (scheduled nightly).
     *
     * Customer-visible activity feed — kept longer than the operational tables,
     * but still bounded.
     */
    public function prunable(): \Illuminate\Database\Eloquent\Builder
    {
        return static::where('created_at', '<', now()->subDays(180));
    }
}
