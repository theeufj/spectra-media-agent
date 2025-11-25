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
        'cloudfront_url',
        'gemini_video_uri',
        'parent_video_id',
        'extension_count',
        'parent_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'extension_count' => 'integer',
    ];

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
            && !empty($this->gemini_video_uri) 
            && ($this->extension_count ?? 0) < 20;
    }
}
