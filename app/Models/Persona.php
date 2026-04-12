<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Persona extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'campaign_id',
        'name',
        'description',
        'demographics',
        'psychographics',
        'pain_points',
        'messaging_angle',
        'tone_adjustments',
        'source',
        'is_active',
    ];

    protected $casts = [
        'demographics' => 'array',
        'psychographics' => 'array',
        'pain_points' => 'array',
        'tone_adjustments' => 'array',
        'is_active' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function adCopies(): HasMany
    {
        return $this->hasMany(AdCopy::class);
    }
}
