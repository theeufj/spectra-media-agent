<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'reason',
        'goals',
        'target_market',
        'voice',
        'total_budget',
        'start_date',
        'end_date',
        'primary_kpi',
        'product_focus',
        'landing_page_url',
        'exclusions',
    ];

    /**
     * A Campaign has many Strategies.
     * This defines the one-to-many relationship between the Campaign and Strategy models.
     * In Go, you might represent this with a slice of Strategy structs: `Strategies []Strategy`.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function strategies(): HasMany
    {
        return $this->hasMany(Strategy::class);
    }
}
