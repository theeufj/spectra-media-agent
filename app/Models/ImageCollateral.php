<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImageCollateral extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'campaign_id',
        'strategy_id',
        'platform',
        's3_path',
        'cloudfront_url',
        'parent_id',
    ];

    /**
     * Get the parent image that this image was refined from.
     */
    public function parent()
    {
        return $this->belongsTo(ImageCollateral::class, 'parent_id');
    }

    /**
     * Get the child images that were refined from this image.
     */
    public function children()
    {
        return $this->hasMany(ImageCollateral::class, 'parent_id');
    }

    /**
     * Get the campaign that this image collateral belongs to.
     */
    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Get the strategy that this image collateral belongs to.
     */
    public function strategy()
    {
        return $this->belongsTo(Strategy::class);
    }
}
