<?php

namespace App\Services\GoogleAds;

use App\Models\Customer;

class AccessibleAccountResolver
{
    public function forCustomer(Customer $customer): array
    {
        $listService = new ListAccessibleCustomers($customer);
        $resourceNames = $listService() ?? [];

        return collect($resourceNames)
            ->map(function (string $resourceName) {
                if (!preg_match('/customers\/(\d+)/', $resourceName, $matches)) {
                    return null;
                }

                return [
                    'id' => $matches[1],
                    'resource_name' => $resourceName,
                    'name' => 'Google Ads Account ' . $matches[1],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}