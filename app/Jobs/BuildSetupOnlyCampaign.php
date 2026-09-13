<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Put the ads in the account, for the customer who paid us to.
 *
 * The one-time setup promises the whole build: the Google Ads account, the
 * conversion tracking, the campaign and the ads, handed over paused. Paying
 * already provisioned the account and the tracking. Nothing built the ads.
 *
 * DeployCampaign is dispatched from exactly three places, all of them a
 * controller — someone clicking Deploy, or an admin clicking it for them. And it
 * only deploys strategies carrying signed_off_at, which is set in exactly two
 * places, both also a controller. So a US$999 customer had to review a strategy
 * and press deploy themselves: precisely the intimidating part they had paid to
 * avoid, on an account they had not been invited into yet.
 *
 * Signing off on their behalf is the point of the engagement rather than a
 * liberty: they bought the build. Nothing goes live regardless —
 * SettleDeployedCampaign pauses a setup-only customer's campaign the moment it
 * deploys, so this fills the account and leaves the switch to them.
 */
class BuildSetupOnlyCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function __construct(public Customer $customer) {}

    /**
     * Dispatch only when the whole engagement is actually ready to build.
     *
     * Each condition is a way this could otherwise run against an account that
     * cannot take it, and every one of them has to hold.
     */
    public static function dispatchIfReady(Customer $customer): void
    {
        $customer = $customer->fresh();

        if (! $customer || $customer->service_type !== 'setup_only') {
            return;
        }

        if ($customer->setup_fee_paid_at === null) {
            Log::info('BuildSetupOnlyCampaign: not paid yet', ['customer_id' => $customer->id]);

            return;
        }

        if (empty($customer->google_ads_customer_id)) {
            Log::info('BuildSetupOnlyCampaign: no Google Ads account yet', ['customer_id' => $customer->id]);

            return;
        }

        self::dispatch($customer);
    }

    public function handle(): void
    {
        $campaign = Campaign::where('customer_id', $this->customer->id)
            ->whereNotNull('auto_generated_at')
            ->orderBy('id')
            ->first();

        if (! $campaign) {
            // The scan produces this during onboarding. Absent means the crawl
            // found too little to write a campaign from, which the customer was
            // already told on the scanning screen.
            Log::warning('BuildSetupOnlyCampaign: no auto-generated campaign to build', [
                'customer_id' => $this->customer->id,
            ]);

            return;
        }

        $strategies = $campaign->strategies()->get();

        if ($strategies->isEmpty()) {
            Log::warning('BuildSetupOnlyCampaign: campaign has no strategies yet', [
                'customer_id' => $this->customer->id,
                'campaign_id' => $campaign->id,
            ]);

            return;
        }

        // Already built — this job is reachable from more than one place in the
        // chain and must not deploy twice.
        if ($strategies->contains(fn ($s) => $s->deployed_at !== null)) {
            Log::info('BuildSetupOnlyCampaign: already deployed', ['campaign_id' => $campaign->id]);

            return;
        }

        $campaign->strategies()->whereNull('signed_off_at')->update(['signed_off_at' => now()]);

        Log::info('BuildSetupOnlyCampaign: building the account for a one-time setup customer', [
            'customer_id' => $this->customer->id,
            'campaign_id' => $campaign->id,
            'strategies' => $strategies->count(),
        ]);

        DeployCampaign::dispatch($campaign->fresh(), useAgents: true);
    }

    public function failed(\Throwable $exception): void
    {
        // A setup-only customer has paid and is waiting on us; a silent failure
        // here is an engagement that never completes.
        report($exception);
        Log::error('BuildSetupOnlyCampaign failed: '.$exception->getMessage(), [
            'customer_id' => $this->customer->id,
        ]);
    }
}
