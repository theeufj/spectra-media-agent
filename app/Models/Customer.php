<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'business_type',
        'description',
        'industry',
        'competitive_strategy',
        'competitive_strategy_updated_at',
        'competitor_analysis_at',
        'country',
        'timezone',
        'currency_code',
        'website',
        'phone',
        'google_ads_customer_id',
        'google_ads_manager_customer_id',
        'google_ads_customer_is_manager',
        'facebook_ads_account_id',
        'facebook_page_id',
        'facebook_page_name',
        'facebook_bm_owned',
        'microsoft_ads_customer_id',
        'microsoft_ads_account_id',
        'linkedin_ads_account_id',
        'gtm_container_id',
        'gtm_account_id',
        'gtm_workspace_id',
        'gtm_config',
        'gtm_installed',
        'gtm_last_verified',
        'cro_audits_used',
        'gtm_detected',
        'gtm_detected_at',
        'average_order_value',
        'agent_thresholds',
        'report_branding',
        'tracking_signing_secret',
        'is_sandbox',
        'sandbox_results',
        'sandbox_expires_at',
    ];

    protected $casts = [
        'gtm_config' => 'array',
        'gtm_installed' => 'boolean',
        'gtm_detected' => 'boolean',
        'gtm_last_verified' => 'datetime',
        'gtm_detected_at' => 'datetime',
        'competitive_strategy' => 'array',
        'competitive_strategy_updated_at' => 'datetime',
        'competitor_analysis_at' => 'datetime',
        'facebook_bm_owned' => 'boolean',
        'google_ads_customer_is_manager' => 'boolean',
        'average_order_value' => 'float',
        'agent_thresholds' => 'array',
        'report_branding' => 'array',
        'is_sandbox' => 'boolean',
        'sandbox_results' => 'array',
        'sandbox_expires_at' => 'datetime',
    ];

    protected $hidden = [
        'tracking_signing_secret',
    ];

    public function scopeSandbox($query)
    {
        return $query->where('is_sandbox', true);
    }

    public function scopeReal($query)
    {
        return $query->where('is_sandbox', false);
    }

    /**
     * Get the list of platforms that have account IDs configured for this customer.
     */
    public function configuredPlatforms(): array
    {
        $platforms = [];

        if (!empty($this->google_ads_customer_id)) {
            $platforms[] = 'google';
        }
        if (!empty($this->facebook_ads_account_id)) {
            $platforms[] = 'facebook';
        }
        if (!empty($this->microsoft_ads_customer_id) && !empty($this->microsoft_ads_account_id)) {
            $platforms[] = 'microsoft';
        }
        if (!empty($this->linkedin_ads_account_id)) {
            $platforms[] = 'linkedin';
        }

        return $platforms;
    }

    /**
     * Prevent google_ads_customer_id from being set to the platform MCC account.
     * All customer accounts must be sub-accounts created under the MCC.
     */
    public function setGoogleAdsCustomerIdAttribute(?string $value): void
    {
        if ($value !== null) {
            $mcc = \App\Models\MccAccount::getActive();
            if ($mcc && $value === $mcc->google_customer_id) {
                throw new \InvalidArgumentException(
                    "Cannot assign the MCC account ({$value}) as a customer's Google Ads account. "
                    . "Customers must have sub-accounts created under the MCC."
                );
            }
        }

        $this->attributes['google_ads_customer_id'] = $value;
    }

    /**
     * The users that belong to the customer.
     */
    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot('role');
    }

    /**
     * Get the pages for the customer.
     */
    public function pages()
    {
        return $this->hasMany(CustomerPage::class);
    }

    /**
     * Get the campaigns for the customer.
     */
    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function keywords()
    {
        return $this->hasMany(Keyword::class);
    }

    /**
     * Get the brand guideline for the customer.
     */
    public function brandGuideline()
    {
        return $this->hasOne(BrandGuideline::class);
    }

    /**
     * Get the competitors for the customer.
     */
    public function competitors(): HasMany
    {
        return $this->hasMany(Competitor::class);
    }

    /**
     * Get the ad spend credit account for the customer.
     */
    public function adSpendCredit()
    {
        return $this->hasOne(AdSpendCredit::class);
    }

    /**
     * Extract a Facebook Page ID from a URL or raw ID string.
     *
     * Supports:
     *   - https://www.facebook.com/profile.php?id=61584812770566
     *   - https://www.facebook.com/YourPageName
     *   - https://facebook.com/YourPageName/
     *   - https://www.facebook.com/p/PageName-61584812770566/
     *   - Raw numeric ID: 61584812770566
     *   - Page slug: YourPageName
     *
     * Returns ['page_id' => string, 'page_name' => string|null] or null if unparseable.
     */
    public static function parseFacebookPageUrl(?string $input): ?array
    {
        if (empty($input)) {
            return null;
        }

        $input = trim($input);

        // Raw numeric ID
        if (preg_match('/^\d{5,}$/', $input)) {
            return ['page_id' => $input, 'page_name' => null];
        }

        // URL format
        if (preg_match('#facebook\.com#i', $input)) {
            $parsed = parse_url($input);
            $path = trim($parsed['path'] ?? '', '/');

            // profile.php?id=123
            if (str_contains($path, 'profile.php')) {
                parse_str($parsed['query'] ?? '', $query);
                if (!empty($query['id']) && preg_match('/^\d+$/', $query['id'])) {
                    return ['page_id' => $query['id'], 'page_name' => null];
                }
            }

            // /p/PageName-123456/ format
            if (preg_match('#^p/(.+?)(?:-(\d{5,}))?/?$#', $path, $m)) {
                return [
                    'page_id' => $m[2] ?? $m[1],
                    'page_name' => str_replace('-', ' ', $m[1]),
                ];
            }

            // /PageName or /PageName/
            if ($path && !str_contains($path, '/') || preg_match('#^[^/]+/?$#', $path)) {
                $slug = trim($path, '/');
                // If slug is numeric, it's a page ID
                if (preg_match('/^\d{5,}$/', $slug)) {
                    return ['page_id' => $slug, 'page_name' => null];
                }
                // Otherwise it's a vanity slug — use as both
                return ['page_id' => $slug, 'page_name' => $slug];
            }
        }

        // Fallback — treat non-empty string as a slug/ID
        return ['page_id' => $input, 'page_name' => null];
    }
}
