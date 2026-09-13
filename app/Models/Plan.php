<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_cents',
        'billing_interval',
        'stripe_price_id',
        'stripe_ad_spend_price_id',
        'features',
        'creative_limits',
        'is_active',
        'is_free',
        'is_popular',
        'cta_text',
        'badge_text',
        'sort_order',
    ];

    protected $casts = [
        'features' => 'array',
        'creative_limits' => 'array',
        'is_active' => 'boolean',
        'is_free' => 'boolean',
        'is_popular' => 'boolean',
        'price_cents' => 'integer',
        'sort_order' => 'integer',
    ];

    protected $appends = ['formatted_price'];

    public function getFormattedPriceAttribute(): string
    {
        if ($this->is_free) {
            return '$0 / month';
        }

        if ($this->price_cents === 0) {
            return 'Contact Us';
        }

        $dollars = number_format($this->price_cents / 100, 0);

        return "\${$dollars} / {$this->billing_interval}";
    }

    /**
     * Which ad platforms a plan slug entitles an account to.
     *
     * The single source of truth for that mapping. Customer::allowedPlatforms()
     * asks this rather than carrying its own copy, because the wizard now needs
     * the same answer from the other direction — given the platforms somebody
     * wants, which plan covers them.
     *
     * @return list<string>
     */
    public static function platformsFor(string $slug, ?string $starterPlatform = null): array
    {
        return match ($slug) {
            // The one-time setup is a Google Ads engagement, named as such on
            // the pricing card and in the receipt.
            'free', 'setup_only' => ['google'],
            // One platform, whichever they chose.
            'starter' => [$starterPlatform ?? 'google'],
            default => ['google', 'facebook', 'microsoft', 'linkedin'],
        };
    }

    /**
     * The cheapest self-serve plan that covers everything in $platforms.
     *
     * Lets the product sell the outcome instead of gating it: the wizard asks
     * where they want to advertise, and this answers what that costs. Before
     * this, a customer had to buy a plan to find out what it let them pick,
     * and saw platforms greyed out with "upgrade to unlock" and no way to know
     * which upgrade.
     *
     * Starter is a special case and the reason this is not a simple subset
     * test: it covers any ONE platform, because starter_platform is the
     * customer's choice, and that choice is exactly what the wizard is
     * collecting.
     */
    public static function cheapestFor(array $platforms): ?self
    {
        $wanted = array_values(array_unique(array_map('strtolower', array_filter($platforms))));

        if (empty($wanted)) {
            return null;
        }

        $candidates = static::query()
            ->where('is_active', true)
            // Self-serve only. Agency is "Contact Us" and cannot be bought from
            // a wizard, so offering it as the answer would be a dead end.
            ->whereNotNull('stripe_price_id')
            ->orderBy('price_cents')
            ->get();

        foreach ($candidates as $plan) {
            $covers = $plan->slug === 'starter'
                ? count($wanted) === 1
                : empty(array_diff($wanted, static::platformsFor($plan->slug)));

            if ($covers) {
                return $plan;
            }
        }

        return null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('price_cents');
    }
}
