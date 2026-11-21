<?php

namespace App\Services\Customers;

use App\Models\Campaign;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

/**
 * Stop everything a customer is spending before their record goes away.
 *
 * Deleting a customer used to be one line — `$customer->delete()` — with an
 * empty deleted() observer behind it. The campaigns on Google, Facebook,
 * Microsoft and LinkedIn kept running and kept charging the customer's card,
 * while the rows recording which campaigns those were had just been destroyed.
 * The single action most likely to be taken *because* someone wanted the
 * spending to stop was the one that removed the means to stop it.
 *
 * So pausing comes first, and it reports honestly: a customer whose campaigns
 * could not be paused must not be quietly deleted anyway.
 */
class DeactivateCustomerService
{
    /**
     * Pause every live campaign this customer has on every platform.
     *
     * @return array{paused: int, failed: int, errors: list<string>}
     */
    public function pauseAllCampaigns(Customer $customer): array
    {
        $paused = 0;
        $failed = 0;
        $errors = [];

        $campaigns = Campaign::where('customer_id', $customer->id)
            ->withDeployedPlatforms()
            ->get();

        foreach ($campaigns as $campaign) {
            // Per campaign, so one platform refusing does not leave the rest
            // running. This loop is the whole point of the service.
            try {
                $result = $this->pauseCampaign($customer, $campaign);

                if ($result === true) {
                    $paused++;
                } elseif (is_string($result)) {
                    $failed++;
                    $errors[] = "Campaign {$campaign->id}: {$result}";
                }
            } catch (\Throwable $e) {
                report($e);
                $failed++;
                $errors[] = "Campaign {$campaign->id}: ".$e->getMessage();
                Log::error('DeactivateCustomer: failed to pause campaign', [
                    'customer_id' => $customer->id,
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('DeactivateCustomer: pause sweep complete', [
            'customer_id' => $customer->id,
            'paused' => $paused,
            'failed' => $failed,
        ]);

        return ['paused' => $paused, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Pause one campaign wherever it is deployed.
     *
     * Returns true when something was paused, null when the campaign was not
     * live anywhere, and an error string when a platform refused.
     *
     * Public because this is now the only implementation: ad spend billing
     * pauses on the third consecutive failed charge and kept its own copy,
     * which called Google's UpdateCampaignStatus::pause() with one of its two
     * required arguments and so pauses nothing.
     */
    public function pauseCampaign(Customer $customer, Campaign $campaign): true|string|null
    {
        $touched = false;
        $problems = [];

        if ($campaign->customer_id !== $customer->id) {
            return 'The workspace does not own this campaign.';
        }
        $entries = \App\Services\Campaigns\CampaignResourceInventory::entries($campaign);
        foreach ($entries as $entry) {
            try {
                if ($entry['platform'] === 'google') {
                    $api = new \App\Services\GoogleAds\CommonServices\UpdateCampaignStatus($customer);
                    $response = $api->pause($entry['account_id'], $entry['resource_name']);
                    $confirmed = $response['success'] && $api->confirmedStatus($entry['account_id'], $entry['resource_name'], 'PAUSED');
                } else {
                    $api = new \App\Services\FacebookAds\CampaignService($customer);
                    $api->updateCampaign($entry['resource_id'], ['status' => 'PAUSED']);
                    $read = $api->getCampaign($entry['resource_id']);
                    $confirmed = (string) ($read['id'] ?? '') === $entry['resource_id'] && ($read['status'] ?? '') === 'PAUSED';
                }
                if ($confirmed) {
                    $touched = true;
                } else {
                    $problems[] = 'Pause unconfirmed on '.$entry['platform'].' campaign '.$entry['resource_id'];
                }
            } catch (\Throwable $e) {
                report($e);
                $problems[] = 'Pause failed on '.$entry['platform'].' campaign '.$entry['resource_id'];
            }
        }

        if ($campaign->microsoft_ads_campaign_id) {
            try {
                (new \App\Services\MicrosoftAds\CampaignService($customer))
                    ->updateStatus($campaign->microsoft_ads_campaign_id, 'Paused');
                $touched = true;
            } catch (\Throwable $e) {
                report($e);
                $problems[] = 'Microsoft: '.$e->getMessage();
            }
        }

        if ($campaign->linkedin_campaign_id) {
            try {
                (new \App\Services\LinkedInAds\CampaignService($customer))
                    ->updateStatus($campaign->linkedin_campaign_id, 'PAUSED');
                $touched = true;
            } catch (\Throwable $e) {
                report($e);
                $problems[] = 'LinkedIn: '.$e->getMessage();
            }
        }

        if ($problems !== []) {
            return implode('; ', $problems);
        }

        if (! $touched) {
            return null;
        }

        // Both columns together — billing filters on `status`, so pausing the
        // ads without it keeps charging for a campaign that is not running.
        $campaign->applyPlatformStatus('PAUSED');

        return true;
    }
}
