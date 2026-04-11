<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreativeBoostPurchase extends Model
{
    protected $fillable = [
        'user_id',
        'stripe_checkout_session_id',
        'image_generations',
        'video_generations',
        'refinements',
        'amount_cents',
        'period',
        'status',
    ];

    protected $casts = [
        'image_generations' => 'integer',
        'video_generations' => 'integer',
        'refinements' => 'integer',
        'amount_cents' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
