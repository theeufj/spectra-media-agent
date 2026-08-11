<?php

namespace App\Observers;

use App\Jobs\ScrapeCustomerWebsite;
use App\Jobs\SetupConversionTracking;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerObserver
{
    /**
     * Handle the Customer "created" event.
     *
     * When a new customer is created with a website URL,
     * automatically dispatch the ScrapeCustomerWebsite job
     * to detect GTM and gather initial website information.
     */
    public function created(Customer $customer): void
    {
        // Auto-generate tracking signing secret for HMAC pixel verification
        if (! $customer->tracking_signing_secret) {
            $customer->updateQuietly(['tracking_signing_secret' => Str::random(64)]);
        }

        if ($customer->website && ! $customer->is_sandbox) {
            Log::info('New customer created with website - dispatching scrape job', [
                'customer_id' => $customer->id,
                'website' => $customer->website,
            ]);

            dispatch(new ScrapeCustomerWebsite($customer));

            $this->ensureConversionTracking($customer);
        }
    }

    /**
     * Handle the Customer "updated" event.
     *
     * When a customer's website URL is updated,
     * automatically dispatch the ScrapeCustomerWebsite job
     * to re-detect GTM with the new website.
     */
    public function updated(Customer $customer): void
    {
        // Check if website was changed and is now populated (skip sandbox customers)
        if ($customer->isDirty('website') && $customer->website && ! $customer->is_sandbox) {
            Log::info('Customer website updated - dispatching scrape job', [
                'customer_id' => $customer->id,
                'old_website' => $customer->getOriginal('website'),
                'new_website' => $customer->website,
            ]);

            dispatch(new ScrapeCustomerWebsite($customer));

            $this->ensureConversionTracking($customer);
        }
    }

    /**
     * Provision conversion tracking as soon as we know the customer's website.
     *
     * This used to happen only two ways: an admin clicking a button, or
     * AutomatedCampaignMaintenance — whose digest loop only covers campaigns
     * already ELIGIBLE or LEARNING with a platform ID. So tracking was set up
     * only for customers who *already had a live campaign*, which is backwards:
     * Smart Bidding needs conversions from the first impression, and the
     * optimisation agents cannot decide anything without conversion data. Every
     * customer's first campaign therefore launched blind and bid on zero
     * conversions — the exact failure that cost this account three weeks of
     * spend on mobile-game inventory.
     *
     * Delayed so ScrapeCustomerWebsite runs first: it detects whether the site
     * already carries a container, which the setup path can then take into
     * account rather than provisioning over the top of it.
     *
     * The job self-skips when conversion_action_id is set, so a second dispatch
     * is harmless.
     */
    private function ensureConversionTracking(Customer $customer): void
    {
        if ($customer->conversion_action_id) {
            return;
        }

        Log::info('Dispatching conversion tracking setup for customer', [
            'customer_id' => $customer->id,
        ]);

        SetupConversionTracking::dispatch($customer)->delay(now()->addMinutes(2));
    }

    /**
     * Handle the Customer "deleted" event.
     */
    public function deleted(Customer $customer): void
    {
        //
    }

    /**
     * Handle the Customer "restored" event.
     */
    public function restored(Customer $customer): void
    {
        //
    }

    /**
     * Handle the Customer "force deleted" event.
     */
    public function forceDeleted(Customer $customer): void
    {
        //
    }
}
