<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PerformanceData extends Model
{
    use HasFactory;

    protected $fillable = [
        'strategy_id',
        'impressions',
        'clicks',
        'conversions',
        'spend',
    ];

    public function strategy()
    {
        return $this->belongsTo(Strategy::class);
    }
}
