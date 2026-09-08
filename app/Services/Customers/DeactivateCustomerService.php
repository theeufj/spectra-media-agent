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

        if ($campaign->google_ads_campaign_id && $customer->google_ads_customer_id) {
            $result = (new \App\Services\GoogleAds\CommonServices\UpdateCampaignStatus($customer))
                ->pause($customer->cleanGoogleCustomerId(), $campaign->googleAdsResourceName());

            $result['success'] ? $touched = true : $problems[] = 'Google: '.($result['error'] ?? 'unknown');
        }

        // Per platform, not per campaign: one platform throwing must not skip
        // the platforms after it, or the campaign carries on spending on those.
        // \Throwable, since a wrong method name is an \Error.
        if ($campaign->facebook_ads_campaign_id) {
            try {
                // updateCampaign with a status payload — Facebook's service has no
                // updateStatus method, unlike Microsoft's and LinkedIn's.
                (new \App\Services\FacebookAds\CampaignService($customer))
                    ->updateCampaign($campaign->facebook_ads_campaign_id, ['status' => 'PAUSED']);
                $touched = true;
            } catch (\Throwable $e) {
                report($e);
                $problems[] = 'Facebook: '.$e->getMessage();
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
