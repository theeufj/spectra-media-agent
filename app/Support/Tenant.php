<?php

namespace App\Support;

class Tenant
{
    /**
     * The tenant config for a key, falling back to the default tenant.
     *
     * @return array<string, mixed>
     */
    public static function config(?string $key): array
    {
        $tenants = config('tenants');

        if ($key) {
            foreach ($tenants as $domain => $tenant) {
                if (is_array($tenant) && ($tenant['key'] ?? null) === $key) {
                    return $tenant + ['domain' => $domain];
                }
            }
        }

        $defaultDomain = $tenants['default'] ?? 'sitetospend.com';
        $config = $tenants[$defaultDomain] ?? $tenants['sitetospend.com'];

        return $config + ['domain' => $defaultDomain];
    }

    public static function name(?string $key): string
    {
        return self::config($key)['name'] ?? 'Site to Spend';
    }

    /**
     * An absolute URL on the tenant's own domain. The default tenant keeps
     * APP_URL (so local dev links stay local); a vertical skin gets its
     * domain, which serves the same app — paths are identical across skins.
     */
    public static function url(?string $key, string $path = '/'): string
    {
        $path = '/'.ltrim($path, '/');

        $config = self::config($key);
        $defaultDomain = config('tenants.default', 'sitetospend.com');

        if (($config['domain'] ?? $defaultDomain) === $defaultDomain) {
            return url($path);
        }

        return 'https://'.$config['domain'].$path;
    }

    /**
     * The blade variables the branded email layout consumes. Shared by
     * mailables (HasTenantBranding) and notification mails (TenantAware) so
     * both kinds of email dress identically.
     *
     * @return array<string, string>
     */
    public static function viewData(?string $key): array
    {
        $config = self::config($key);

        return [
            'tenantName' => $config['name'] ?? 'Site to Spend',
            'tenantPrimary' => $config['colors']['primary'] ?? '#ff4d00',
            'tenantDark' => $config['colors']['dark'] ?? '#cc3d00',
            'tenantAccent' => $config['colors']['accent'] ?? '#ffc300',
            'tenantLogoText' => $config['logo_text'] ?? 'Site to Spend',
            // For links in email bodies: the skin's own domain serves the
            // same app, so path-only routes prefixed with this stay on-brand.
            'tenantBaseUrl' => rtrim(self::url($key, '/'), '/'),
        ];
    }

    /**
     * Resolve a tenant key from whatever models an email or notification
     * holds. Customers own the answer; campaigns borrow their customer's;
     * a user's own key is the last resort (they may belong to several).
     */
    public static function keyFromModels(object ...$candidates): ?string
    {
        // Derived from the same customer the email log files under
        // (customerFromModels), so branding and log-filing can never
        // disagree about whose email this is.
        if ($key = self::customerFromModels(...$candidates)?->tenant_key) {
            return $key;
        }

        foreach ($candidates as $model) {
            if ($model instanceof \App\Models\User && $model->tenant_key) {
                return $model->tenant_key;
            }
        }

        return null;
    }

    /**
     * The customer an email or notification is about, resolved from the
     * models it holds — same walk as keyFromModels, minus the user fallback
     * (a user may belong to several customers). Feeds the per-customer
     * email log.
     */
    public static function customerFromModels(object ...$candidates): ?\App\Models\Customer
    {
        foreach ($candidates as $model) {
            if ($model instanceof \App\Models\Customer) {
                return $model;
            }
        }
        foreach ($candidates as $model) {
            if ($model instanceof \App\Models\Campaign && $model->customer) {
                return $model->customer;
            }
        }
        foreach ($candidates as $model) {
            if ($model instanceof \Illuminate\Database\Eloquent\Model
                && ! $model instanceof \App\Models\User
                && isset($model->customer)
                && $model->customer instanceof \App\Models\Customer) {
                return $model->customer;
            }
        }

        return null;
    }
}
