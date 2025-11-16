<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Recommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'type',
        'target_entity',
        'parameters',
        'rationale',
        'status',
        'requires_approval',
    ];

    protected $casts = [
        'target_entity' => 'array',
        'parameters' => 'array',
        'requires_approval' => 'boolean',
    ];
}
