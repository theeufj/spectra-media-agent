<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreativeBrief extends Model
{
    protected $fillable = [
        'campaign_id',
        'customer_id',
        'platform',
        'brief_type',
        'status',
        'created_by_agent',
        'ai_brief',
        'context',
        'actioned_at',
    ];

    protected $casts = [
        'context'     => 'array',
        'actioned_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function markActioned(): void
    {
        $this->update(['status' => 'actioned', 'actioned_at' => now()]);
    }

    public function dismiss(): void
    {
        $this->update(['status' => 'dismissed']);
    }
}
