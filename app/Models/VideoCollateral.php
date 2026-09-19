<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoCollateral extends Model
{
    /** Reserve quota and a concept slot together, before requesting a paid render. */
    public static function reserve(Campaign $campaign, array $attributes): ?self
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($campaign, $attributes) {
            Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $exists = static::where('campaign_id', $campaign->id)
                ->where('platform', $attributes['platform'])
                ->where('variation_index', $attributes['variation_index'])
                ->where('is_active', true)->whereIn('status', ['pending', 'generating', 'completed'])->exists();
            if ($exists || static::remainingForCampaign($campaign) < 1) {
                return null;
            }

            return static::create($attributes + ['campaign_id' => $campaign->id]);
        });
    }

    protected $fillable = [
        'campaign_id',
        'generation_metadata',
        'variation_index',
        'strategy_id',
        'platform',
        'script',
        'status',
        'operation_name',
        's3_path',
        'youtube_video_id',
        'cloudfront_url',
        'gemini_video_uri',
        'parent_video_id',
        'extension_count',
        'refinement_depth',
        'parent_id',
        'is_active',
        'should_deploy',
        'source',
        'provider',
        'narration_finalized_at',
    ];

    protected $casts = [
        'generation_metadata' => 'array',
        'is_active' => 'boolean',
        'extension_count' => 'integer',
        'refinement_depth' => 'integer',
    ];

    /**
     * All media a strategy may use: its own rows plus campaign-level rows
     * (strategy_id null, e.g. wizard uploads). Mirrors
     * ImageCollateral::scopeForStrategy — see the rationale there.
     */
    public function scopeForStrategy($query, Strategy $strategy)
    {
        return $query->where(function ($q) use ($strategy) {
            $q->where('strategy_id', $strategy->id)
                ->orWhere(function ($campaignLevel) use ($strategy) {
                    $campaignLevel->where('campaign_id', $strategy->campaign_id)
                        ->whereNull('strategy_id');
                });
        });
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function strategy()
    {
        return $this->belongsTo(Strategy::class);
    }

    public function parent()
    {
        return $this->belongsTo(VideoCollateral::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(VideoCollateral::class, 'parent_id');
    }

    public function parentVideo()
    {
        return $this->belongsTo(VideoCollateral::class, 'parent_video_id');
    }

    public function extensions()
    {
        return $this->hasMany(VideoCollateral::class, 'parent_video_id');
    }

    public function canBeExtended(): bool
    {
        return $this->status === 'completed'
            && ! empty($this->gemini_video_uri)
            && ($this->extension_count ?? 0) < 20;
    }

    /**
     * How many more videos this campaign's plan will pay for.
     *
     * Images have had a gate since the free tier existed; video never did, and
     * video is the expensive one. Measured from ai_costs: $1.88 a video on
     * Grok, $3.20 per 8-second segment when the Veo fallback runs, so a
     * 24-second script is $9.60 — roughly what 240 images cost. Dispatching
     * more concepts without a ceiling is how one campaign quietly spends most
     * of a Starter subscription on creative.
     *
     * Counted per campaign against the plan's allowance, matching how the image
     * gate reads. A plan with no video allowance gets none, which is the free
     * tier and is deliberate.
     */
    public static function remainingForCampaign(Campaign $campaign): int
    {
        $customer = $campaign->customer;

        if (! $customer) {
            return 0;
        }

        $limit = (int) ($customer->resolvePlan()->creative_limits['video_generations'] ?? 0);

        if ($limit <= 0) {
            return 0;
        }

        // Failed rows do not count: a video that never arrived is not one the
        // customer received, and refusing to retry it would strand the campaign
        // short of what its plan allows.
        $used = static::where('campaign_id', $campaign->id)
            ->where('status', '!=', 'failed')
            ->count();

        return max(0, $limit - $used);
    }
}
