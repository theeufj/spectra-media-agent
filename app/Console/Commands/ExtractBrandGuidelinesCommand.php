<?php

namespace App\Console\Commands;

use App\Jobs\ExtractBrandGuidelines;
use App\Models\Customer;
use Illuminate\Console\Command;

class ExtractBrandGuidelinesCommand extends Command
{
    protected $signature = 'brand:extract {customer_id? : The customer ID to extract guidelines for}';
    protected $description = 'Extract brand guidelines for a customer or all customers';

    public function handle(): int
    {
        if ($customerId = $this->argument('customer_id')) {
            $customer = Customer::find($customerId);
            
            if (!$customer) {
                $this->error("Customer {$customerId} not found");
                return 1;
            }
            
            $this->info("Extracting brand guidelines for customer {$customer->id}...");
            dispatch(new ExtractBrandGuidelines($customer));
            $this->info("Job dispatched successfully");
            
        } else {
            $this->info("Extracting brand guidelines for all customers...");
            
            Customer::whereNotNull('website_url')
                ->chunk(10, function ($customers) {
                    foreach ($customers as $customer) {
                        dispatch(new ExtractBrandGuidelines($customer));
                    }
                });
            
            $this->info("Jobs dispatched for all customers");
        }

        return 0;
    }
}
