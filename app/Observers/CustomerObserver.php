<?php

namespace App\Observers;

use App\Jobs\CrawlSitemap;
use App\Jobs\ScrapeCustomerWebsite;
use App\Jobs\SendGoogleAdsLinkInvitation;
use App\Jobs\SetupConversionTracking;
use App\Models\Customer;
use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        if ($customer->website && ! $customer->is_sandbox) {
            Log::info('New customer created with website - dispatching scrape job', [
                'customer_id' => $customer->id,
                'website' => $customer->website,
            ]);

            dispatch(new ScrapeCustomerWebsite($customer));

            $this->ensureConversionTracking($customer);
        }

        // Outside the website check on purpose: a customer can arrive with an
        // existing Google Ads account before we know their site.
        $this->ensureGoogleAdsLink($customer);
        // Account provisioning deliberately does NOT happen here — creating a
        // real Google Ads sub-account for every signup minted accounts for
        // tire-kickers that had to be cancelled by hand. It fires at
        // deploy-intent instead: ProvisionGoogleAdsAccount::dispatchIfNeeded
        // on budget confirmation and setup-fee payment.
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

            $oldHost = strtolower((string) parse_url((string) $customer->getOriginal('website'), PHP_URL_HOST));
            foreach (KnowledgeBase::where('customer_id', $customer->id)->where('source_type', 'url')->whereNull('excluded_at')->get() as $source) {
                if ($oldHost && strtolower((string) parse_url((string) $source->url, PHP_URL_HOST)) === $oldHost) {
                    $source->update(['excluded_at' => now()]);
                }
            }
            $customer->brandGuideline?->update([
                'user_verified' => false,
                'approved_version' => null,
                'profile_version' => $customer->brandGuideline->profile_version + 1,
                'extraction_warning' => 'The website address changed. This profile needs a fresh source review before new ads use it.',
            ]);
            $owner = Auth::user() ?? $customer->users()->first();
            if ($owner) {
                DB::afterCommit(function () use ($customer, $owner) {
                    Cache::forget("crawl:budget:{$customer->id}");
                    CrawlSitemap::forCustomer($customer, $owner);
                });
            }

            dispatch(new ScrapeCustomerWebsite($customer));

            $this->ensureConversionTracking($customer);
        }

        $this->ensureGoogleAdsLink($customer);
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
     * Ask to manage the customer's existing Google Ads account.
     *
     * Google will not let a manager account create client accounts through the
     * API until it has managed roughly US$1,000 of spend. Linking an account the
     * customer already owns carries no such threshold, so customers who arrive
     * with one can be onboarded now instead of waiting for that gate to clear.
     *
     * Fires on creation and whenever the account id is added later, which is the
     * common case — customers rarely have it to hand at sign-up. The job
     * re-checks state before sending, so a second dispatch is harmless.
     */
    private function ensureGoogleAdsLink(Customer $customer): void
    {
        if ($customer->is_sandbox || ! $customer->google_ads_customer_id) {
            return;
        }

        if (in_array($customer->google_ads_link_status, ['active', 'pending'], true)) {
            return;
        }

        Log::info('Requesting Google Ads account link for customer', [
            'customer_id' => $customer->id,
        ]);

        SendGoogleAdsLinkInvitation::dispatch($customer);
    }
}
