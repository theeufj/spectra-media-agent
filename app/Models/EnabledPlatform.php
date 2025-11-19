<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnabledPlatform extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_enabled',
        'sort_order',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Scope to get only enabled platforms.
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope to order by sort_order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Get all enabled platform names.
     */
    public static function getEnabledPlatformNames(): array
    {
        return static::enabled()->ordered()->pluck('name')->toArray();
    }
}
