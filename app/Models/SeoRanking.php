<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property \Illuminate\Support\Carbon|null $reporting_start
 * @property \Illuminate\Support\Carbon|null $reporting_end
 */
class SeoRanking extends Model
{
    use BelongsToCustomer;
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
        'source', 'average_position', 'previous_average_position', 'average_change',
        'reporting_start', 'reporting_end', 'clicks', 'impressions', 'ctr',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'previous_position' => 'integer',
            'change' => 'integer',
            'date' => 'date',
            'average_position' => 'float', 'previous_average_position' => 'float', 'average_change' => 'float',
            'reporting_start' => 'date', 'reporting_end' => 'date',
            'clicks' => 'integer', 'impressions' => 'integer', 'ctr' => 'float',
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
