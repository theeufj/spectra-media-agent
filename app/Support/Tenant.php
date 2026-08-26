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
     * Resolve a tenant key from whatever models an email or notification
     * holds. Customers own the answer; campaigns borrow their customer's;
     * a user's own key is the last resort (they may belong to several).
     */
    public static function keyFromModels(object ...$candidates): ?string
    {
        foreach ($candidates as $model) {
            if ($model instanceof \App\Models\Customer && $model->tenant_key) {
                return $model->tenant_key;
            }
        }
        foreach ($candidates as $model) {
            if ($model instanceof \App\Models\Campaign && $model->customer?->tenant_key) {
                return $model->customer->tenant_key;
            }
        }
        // Anything else that belongs to a customer (invitations, strategies…).
        foreach ($candidates as $model) {
            if ($model instanceof \Illuminate\Database\Eloquent\Model
                && ! $model instanceof \App\Models\User
                && isset($model->customer)
                && $model->customer instanceof \App\Models\Customer
                && $model->customer->tenant_key) {
                return $model->customer->tenant_key;
            }
        }

        foreach ($candidates as $model) {
            if ($model instanceof \App\Models\User && $model->tenant_key) {
                return $model->tenant_key;
            }
        }

        return null;
    }
}
