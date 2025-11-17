<?php

namespace App\Observers;

use App\Jobs\ScrapeCustomerWebsite;
use App\Models\Customer;
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
        if ($customer->website) {
            Log::info('New customer created with website - dispatching scrape job', [
                'customer_id' => $customer->id,
                'website' => $customer->website,
            ]);

            dispatch(new ScrapeCustomerWebsite($customer));
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
        // Check if website was changed and is now populated
        if ($customer->isDirty('website') && $customer->website) {
            Log::info('Customer website updated - dispatching scrape job', [
                'customer_id' => $customer->id,
                'old_website' => $customer->getOriginal('website'),
                'new_website' => $customer->website,
            ]);

            dispatch(new ScrapeCustomerWebsite($customer));
        }
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
