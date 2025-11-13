<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoCollateral extends Model
{
    protected $fillable = [
        'campaign_id',
        'strategy_id',
        'platform',
        'status',
        'operation_name',
        's3_path',
        'cloudfront_url',
        'parent_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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
}
