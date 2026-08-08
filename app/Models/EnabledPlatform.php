<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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

    /**
     * Is this ad platform switched on?
     *
     * This toggle used to govern only the campaign wizard and strategy
     * generation — every scheduled job gated on whether a customer/campaign had
     * a platform ID instead. So disabling a platform stopped you creating new
     * campaigns on it while health checks, self-healing and performance fetches
     * carried on calling its API. Operational paths now consult this, which makes
     * the admin switch behave like the kill switch it appears to be.
     *
     * Cached because callers run it inside per-campaign loops. Unknown platforms
     * default to enabled so that adding an integration without seeding a row
     * doesn't silently disable it.
     *
     * @param  string  $platform  slug or name — 'microsoft', 'Microsoft', 'microsoft_ads'
     */
    public static function isEnabled(string $platform): bool
    {
        $slug = static::normaliseSlug($platform);

        return static::enabledSlugs()[$slug] ?? true;
    }

    /** @return array<string, bool> slug => enabled */
    public static function enabledSlugs(): array
    {
        return Cache::remember('enabled_platform_slugs', 300, function () {
            return static::query()
                ->get(['slug', 'name', 'is_enabled'])
                ->mapWithKeys(fn ($p) => [
                    static::normaliseSlug($p->slug ?: $p->name) => (bool) $p->is_enabled,
                ])
                ->all();
        });
    }

    /** Drop the _ads suffix and casing so 'microsoft_ads' and 'Microsoft' agree. */
    public static function normaliseSlug(string $platform): string
    {
        return preg_replace('/_?ads$/', '', strtolower(trim($platform))) ?: strtolower(trim($platform));
    }

    protected static function booted(): void
    {
        $flush = fn () => Cache::forget('enabled_platform_slugs');

        static::saved($flush);
        static::deleted($flush);
    }
}
