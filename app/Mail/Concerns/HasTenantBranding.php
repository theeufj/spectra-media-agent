<?php

namespace App\Mail\Concerns;

trait HasTenantBranding
{
    protected function tenantViewData(?string $tenantKey = null): array
    {
        $key = $tenantKey ?? ($this->tenantKey ?? null);

        $tenants = config('tenants');
        $config = null;

        if ($key) {
            foreach ($tenants as $domain => $tenant) {
                if (is_array($tenant) && ($tenant['key'] ?? null) === $key) {
                    $config = $tenant;
                    break;
                }
            }
        }

        if (!$config) {
            $defaultDomain = $tenants['default'] ?? 'sitetospend.com';
            $config = $tenants[$defaultDomain] ?? $tenants['sitetospend.com'];
        }

        return [
            'tenantName'    => $config['name'] ?? 'Site to Spend',
            'tenantPrimary' => $config['colors']['primary'] ?? '#ff4d00',
            'tenantDark'    => $config['colors']['dark'] ?? '#cc3d00',
            'tenantAccent'  => $config['colors']['accent'] ?? '#ffc300',
            'tenantLogoText'=> $config['logo_text'] ?? 'Site to Spend',
        ];
    }
}
