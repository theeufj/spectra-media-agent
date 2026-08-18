<?php

namespace App\Console\Commands;

use App\Jobs\ProvisionGoogleAdsAccount;
use App\Models\Customer;
use Illuminate\Console\Command;

/**
 * Give an ad account to the customers who signed up without one.
 *
 * A fresh manager account cannot create client accounts until it has managed
 * roughly US$1,000 of spend. Every signup from March onwards therefore arrived
 * with nowhere to advertise, and the only visible symptom was conversion
 * tracking failing on "Google Ads account not connected" — a condition neither
 * the customer nor an admin could resolve.
 *
 * The gate has cleared. New customers are provisioned by the observer; this is
 * for the backlog.
 */
class BackfillGoogleAdsAccounts extends Command
{
    protected $signature = 'googleads:backfill-accounts
                            {--customer= : Restrict to one customer id}
                            {--dry-run : List who would get an account}';

    protected $description = 'Create Google Ads accounts for customers who never got one';

    public function handle(): int
    {
        $customers = Customer::query()
            ->whereNull('google_ads_customer_id')
            ->where('is_sandbox', false)
            // Customers bringing their own account are mid-invitation; creating
            // a second one would leave them advertising from the wrong place.
            ->where(function ($q) {
                $q->whereNull('google_ads_link_status')
                    ->orWhereNotIn('google_ads_link_status', ['pending', 'active']);
            })
            ->when($this->option('customer'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('id')
            ->get();

        if ($customers->isEmpty()) {
            $this->info('Every customer already has an account or is linking their own.');

            return self::SUCCESS;
        }

        $this->line("{$customers->count()} customer(s) without a Google Ads account:");

        foreach ($customers as $customer) {
            $this->line(sprintf(
                '  #%-3d %-34s %s / %s',
                $customer->id,
                mb_substr($customer->name, 0, 34),
                $customer->currency_code ?: 'AUD',
                $customer->timezone ?: config('app.timezone')
            ));
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no accounts created.');

            return self::SUCCESS;
        }

        // Queued rather than created inline: account creation is rate limited,
        // and a failure part-way through should not strand the rest.
        foreach ($customers as $customer) {
            ProvisionGoogleAdsAccount::dispatch($customer);
        }

        $this->newLine();
        $this->info("Queued provisioning for {$customers->count()} customer(s). Watch AgentActivity for results.");

        return self::SUCCESS;
    }
}
