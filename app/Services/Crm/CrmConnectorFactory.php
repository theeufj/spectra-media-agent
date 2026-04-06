<?php

namespace App\Services\Crm;

use App\Models\CrmIntegration;

class CrmConnectorFactory
{
    public static function make(CrmIntegration $integration): CrmConnectorInterface
    {
        $credentials = $integration->credentials ?? [];

        return match ($integration->provider) {
            'hubspot' => new HubSpotConnector($credentials),
            'salesforce' => new SalesforceConnector($credentials),
            default => throw new \InvalidArgumentException("Unsupported CRM provider: {$integration->provider}"),
        };
    }
}
