<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoRanking extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'keyword',
        'domain',
        'position',
        'url',
        'search_engine',
        'previous_position',
        'change',
        'date',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'previous_position' => 'integer',
            'change' => 'integer',
            'date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeForKeyword($query, string $keyword)
    {
        return $query->where('keyword', $keyword);
    }

    public function scopeImproved($query)
    {
        return $query->where('change', '>', 0);
    }

    public function scopeDeclined($query)
    {
        return $query->where('change', '<', 0);
    }

    public function scopeTopTen($query)
    {
        return $query->where('position', '<=', 10);
    }
}
