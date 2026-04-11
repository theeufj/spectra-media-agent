<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreativeUsage extends Model
{
    protected $fillable = [
        'user_id',
        'period',
        'image_generations_used',
        'video_generations_used',
        'refinements_used',
        'bonus_image_generations',
        'bonus_video_generations',
        'bonus_refinements',
    ];

    protected $casts = [
        'image_generations_used' => 'integer',
        'video_generations_used' => 'integer',
        'refinements_used' => 'integer',
        'bonus_image_generations' => 'integer',
        'bonus_video_generations' => 'integer',
        'bonus_refinements' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
