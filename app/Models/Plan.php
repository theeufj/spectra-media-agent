<?php

namespace App\Models;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @property list<string>|null $legacy_stripe_price_ids */
class Plan extends Model
{
    public const int STARTER_MONTHLY_PRICE_CENTS = 9900;

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
        'legacy_stripe_price_ids' => 'array',
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
    public static function platformsFor(string $slug): array
    {
        return match ($slug) {
            // The one-time setup is a Google Ads engagement, named as such on
            // the pricing card and in the receipt.
            'free', 'setup_only', 'starter' => ['google'],
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
     */
    public static function cheapestFor(array $platforms, ?string $tenantKey = null): ?self
    {
        $wanted = array_values(array_unique(array_map('strtolower', array_filter($platforms))));

        if (empty($wanted)) {
            return null;
        }

        $candidates = static::offeredTo($tenantKey)
            // Self-serve only. Agency is "Contact Us" and cannot be bought from
            // a wizard, so offering it as the answer would be a dead end.
            ->whereNotNull('stripe_price_id')
            ->orderBy('price_cents')
            ->get();

        foreach ($candidates as $plan) {
            if (empty(array_diff($wanted, static::platformsFor($plan->slug)))) {
                return $plan;
            }
        }

        return null;
    }

    /** Recognize past prices on subscriptions without offering them for new checkouts. */
    public function scopeForStripePrices(Builder $query, array $prices): Builder
    {
        $prices = array_values(array_unique(array_filter($prices, 'is_string')));

        return $query->where(function (Builder $match) use ($prices) {
            $match->whereIn('stripe_price_id', $prices);
            foreach ($prices as $price) {
                $match->orWhereJsonContains('legacy_stripe_price_ids', $price);
            }
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** New purchases only. Existing subscriptions resolve against their original prices. */
    public function scopeOfferedTo(Builder $query, ?string $tenantKey): Builder
    {
        $query->where('is_active', true);

        if (Tenant::singlePlan($tenantKey)) {
            $query->where('slug', 'starter')
                ->where('price_cents', self::STARTER_MONTHLY_PRICE_CENTS)
                ->where('billing_interval', 'month')
                ->where('stripe_price_id', '<>', '')
                ->where('is_free', false);
        }

        return $query;
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('price_cents');
    }
}
