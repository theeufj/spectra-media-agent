<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoCollateral extends Model
{
    protected $fillable = [
        'campaign_id',
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
    ];

    protected $casts = [
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

    public function campaign()
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
}
