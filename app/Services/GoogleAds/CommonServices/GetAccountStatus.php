<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;

class GetAccountStatus extends BaseGoogleAdsService
{
    public function __invoke(string $customerId): ?array
    {
        $this->ensureClient();

        $query    = 'SELECT customer.status, customer.manager, customer.descriptive_name FROM customer LIMIT 1';
        $response = $this->searchQuery($customerId, $query);

        foreach ($response->getIterator() as $row) {
            $c = $row->getCustomer();
            return [
                'status'     => $c->getStatus(),
                'is_manager' => $c->getManager(),
                'name'       => $c->getDescriptiveName(),
            ];
        }

        return null;
    }
}
