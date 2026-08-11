<?php

namespace App\Services\Testing\Sandbox;

use App\Contracts\Ads\AdStatusSource;
use App\Models\Customer;

/**
 * Deterministic ad-status fixtures for sandbox customers.
 *
 * Includes one disapproved ad on purpose: SelfHealingAgent exists to notice
 * exactly that, and a fixture where everything is fine would exercise none of
 * its healing paths.
 */
class SandboxAdStatusSource implements AdStatusSource
{
    public function __construct(private Customer $customer) {}

    public function __invoke(string $customerId, ?string $campaignResourceName = null, ?string $adGroupResourceName = null): array
    {
        $base = $campaignResourceName ?? "customers/{$customerId}/campaigns/sandbox";

        return [
            [
                'ad_id' => 'sandbox-ad-'.$this->customer->id.'-1',
                'resource_name' => $base.'/adGroupAds/sandbox-1',
                'status' => 'ENABLED',
                'approval_status' => 'DISAPPROVED',
                'policy_topics' => ['DESTINATION_NOT_WORKING'],
            ],
            [
                'ad_id' => 'sandbox-ad-'.$this->customer->id.'-2',
                'resource_name' => $base.'/adGroupAds/sandbox-2',
                'status' => 'ENABLED',
                'approval_status' => 'APPROVED',
                'policy_topics' => [],
            ],
        ];
    }
}
