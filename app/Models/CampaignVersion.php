<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A point-in-time snapshot of a campaign's strategy.
 *
 * The campaign_versions table has existed since 2025 and
 * SummarizeCampaignHistoryService reads it through $campaign->versions(), but
 * neither this model nor that relation was ever written, so the service raised
 * "call to undefined method" on every call.
 */
class CampaignVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'version_number',
        'strategy_snapshot',
    ];

    protected $casts = [
        'strategy_snapshot' => 'array',
        'version_number' => 'integer',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
