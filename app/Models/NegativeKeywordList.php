<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NegativeKeywordList extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'name', 'keywords', 'applied_to_campaigns', 'created_by',
    ];

    protected $casts = [
        'keywords' => 'array',
        'applied_to_campaigns' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
