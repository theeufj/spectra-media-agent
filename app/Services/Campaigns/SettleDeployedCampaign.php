<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Jobs\RecordSiteConversion;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use Illuminate\Support\Facades\Log;

/**
 * Bring a campaign's lifecycle status into line once its objects are live.
 *
 * This used to live at the bottom of DeployCampaign::handle(), which meant it
 * only ran when that job reached its final lines. It frequently does not: the
 * job talks to four ad platforms in sequence, and a worker restart part-way
 * through leaves the strategies mid-flight and the campaign untouched.
 *
 * ReconcileStuckDeployments then picks those strategies up an hour later,
 * asks the platform whether the objects actually exist, and marks the ones that
 * do as `deployed`. It had no way to finish the other half of the job, so the
 * campaign stayed at `draft` while its ads served.
 *
 * That is not a cosmetic mismatch. `campaigns.status` is what the optimisation
 * side of the product filters on — CampaignFetchPerformanceData, HealthCheck,
 * SelfHealing, AudienceIntelligence, PortfolioOptimization, BudgetAllocation
 * and DetectKeywordCannibalization all select `status = active` — while
 * ProcessDailyAdSpendBilling deliberately also matches `platform_status =
 * ENABLED` so a pause can never outrun the billing. The two together mean a
 * campaign in this state is charged for every day, and looked at by nothing:
 * no performance data is fetched for it, so it is invisible in reports, and no
 * agent will notice it failing.
 *
 * Campaign::applyPlatformStatus() will not fix it either, and should not — it
 * refuses to promote a draft on purpose, because a draft carrying a platform id
 * is ambiguous when all you have is a status read. This class is the other
 * case: the deployment is known to have succeeded, because we are the ones who
 * ran it.
 */
class SettleDeployedCampaign
{
    /**
     * @return bool whether the lifecycle status moved
     */
    public function settle(Campaign $campaign): bool
    {
        $customer = $campaign->customer;

        if (! $customer) {
            return false;
        }

        if ($customer->service_type === 'setup_only') {
            return $this->settleSetupOnly($campaign, $customer);
        }

        if ($campaign->status === CampaignStatus::Active) {
            return false;
        }

        $campaign->update(['status' => CampaignStatus::Active]);
        RecordSiteConversion::dispatch($customer, 'campaign_live');

        return true;
    }

    /**
     * One-time setup: "everything arrives paused" is a promise in the receipt
     * email, so it is enforced mechanically rather than trusted to the deploy
     * path. The customer flips the switch themselves, on their own billing.
     *
     * Ordering matters. The platform is paused first and the column second, so
     * a failure part-way leaves a campaign that is paused-but-marked-live
     * rather than live-but-marked-paused. The first is a reporting problem; the
     * second spends a customer's money while every dashboard says it is not.
     */
    private function settleSetupOnly(Campaign $campaign, Customer $customer): bool
    {
        $this->pauseOnPlatform($campaign, $customer);

        if ($campaign->status === CampaignStatus::Paused) {
            return false;
        }

        $campaign->update(['status' => CampaignStatus::Paused]);

        return true;
    }

    private function pauseOnPlatform(Campaign $campaign, Customer $customer): void
    {
        $customerId = preg_replace('/[^0-9]/', '', (string) $customer->google_ads_customer_id);

        /*
           google_ads_campaign_id holds a resource name, not a bare id.

           Stripping non-digits from "customers/9654834654/campaigns/24246329924"
           glues the customer id onto the front of the campaign id and produces
           965483465424246329924 — a campaign that does not exist. So every
           setup-only pause asked Google to pause nothing, the call failed, the
           column was set to Paused regardless, and MonitorCampaignStatus read
           ENABLED an hour later and correctly flipped us back to Active.

           Every one-time setup campaign has therefore gone live, which is the
           exact opposite of what the receipt email, the Create my ads dialog
           and the deployment screen all promise. Found by noticing a campaign
           this code had "paused" sitting at active a day later.

           The resource name is what the API wants anyway, so it is used as
           stored and only built when the column holds a bare id.
        */
        $resourceName = (string) $campaign->google_ads_campaign_id;

        if (! str_contains($resourceName, '/')) {
            $bare = preg_replace('/[^0-9]/', '', $resourceName);
            $resourceName = $bare === '' ? '' : "customers/{$customerId}/campaigns/{$bare}";
        }

        if ($customerId === '' || $resourceName === '') {
            return;
        }

        try {
            $result = $this->campaignStatusService($customer)->execute(
                $customerId,
                $resourceName,
                'PAUSED'
            );

            AgentActivity::record(
                'deployment',
                'setup_only_paused',
                "Paused \"{$campaign->name}\" after build — one-time setup customers launch it themselves.",
                $campaign->customer_id,
                $campaign->id,
                $result
            );
        } catch (\Throwable $e) {
            // A setup-only campaign left running spends the customer's money on
            // a plan that never agreed to it, so this has to reach the admin
            // dashboard rather than only the log.
            report($e);
            Log::error('SettleDeployedCampaign: could not pause setup-only campaign', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Overridden in tests; the constructor talks to Google. */
    protected function campaignStatusService(Customer $customer): UpdateCampaignStatus
    {
        return new UpdateCampaignStatus($customer);
    }
}
