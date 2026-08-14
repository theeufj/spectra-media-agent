<?php

namespace App\Console\Commands;

use App\Jobs\SendGoogleAdsLinkInvitation;
use App\Models\Customer;
use App\Models\MccAccount;
use App\Services\GoogleAds\CreateCustomerClientLink;
use Illuminate\Console\Command;

/**
 * Send a manager-link invitation to a customer's existing Google Ads account.
 *
 * Onboarding normally does this on its own — CustomerObserver dispatches
 * SendGoogleAdsLinkInvitation as soon as a customer's account id is set. This
 * command exists for the cases that fall outside that: verifying the API path
 * against a known account, re-sending after a customer refused by mistake, and
 * support handling an id that arrived by email rather than through the form.
 *
 * Two modes, because they answer different questions:
 *
 *   --raw   sends the invitation and nothing else. Touches no customer record.
 *           This is the mode for "does the API call actually work", where
 *           writing an account id onto a live customer would be a side effect
 *           rather than the point.
 *
 *   default goes through the job, which is the real onboarding path: it records
 *           link status, stores the resource name so the hourly poller can
 *           follow it, and emails the customer instructions for accepting.
 */
class GoogleAdsLinkAccount extends Command
{
    protected $signature = 'google-ads:link-account
                            {customer : Customer id in this application}
                            {account : The customer\'s 10-digit Google Ads account id}
                            {--raw : Send the invitation only; do not persist link state or email anyone}';

    protected $description = "Invite a customer's existing Google Ads account to be managed by the MCC";

    public function handle(): int
    {
        $customer = Customer::find($this->argument('customer'));

        if (! $customer) {
            $this->error('No customer with id '.$this->argument('customer'));

            return self::FAILURE;
        }

        $clientId = preg_replace('/[^0-9]/', '', (string) $this->argument('account'));

        // Ten digits exactly. Anything else is a typo, and Google would spend a
        // round trip telling us so.
        if (strlen($clientId) !== 10) {
            $this->error('"'.$this->argument('account').'" is not a 10-digit Google Ads account id');

            return self::FAILURE;
        }

        $mcc = MccAccount::getActive();

        if (! $mcc) {
            $this->error('No active MCC account configured');

            return self::FAILURE;
        }

        $managerId = preg_replace('/[^0-9]/', '', $mcc->google_customer_id);

        if ($managerId === $clientId) {
            $this->error('Refusing to link the manager account to itself');

            return self::FAILURE;
        }

        $this->line('Customer : '.$customer->name.' (#'.$customer->id.')');
        $this->line('Manager  : '.$managerId);
        $this->line('Account  : '.$clientId);

        if (! $this->option('raw')) {
            // The onboarding path. Setting the account id is the correct
            // behaviour here — it is what the customer supplied — and the job
            // handles status, resource name and the instruction email.
            $customer->update(['google_ads_customer_id' => $clientId]);

            (new SendGoogleAdsLinkInvitation($customer->fresh()))->handle();

            $after = $customer->fresh();
            $this->line('Status   : '.($after->google_ads_link_status ?? 'not set — check the log'));
            $this->line('Resource : '.($after->google_ads_link_resource_name ?? '—'));
            $this->line('Emailed  : '.$customer->users->pluck('email')->implode(', '));

            return $after->google_ads_link_status === 'pending' ? self::SUCCESS : self::FAILURE;
        }

        $this->warn('Raw mode: no customer record will be modified and no email sent.');

        try {
            $result = (new CreateCustomerClientLink($customer))($managerId, $clientId);
        } catch (\Throwable $e) {
            report($e);
            $this->error('Invitation failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $result) {
            $this->error('Google accepted the call but returned no link.');

            return self::FAILURE;
        }

        $this->info('Invitation sent: '.$result['resourceName']);
        $this->line('It is now pending in that account under Admin > Access and security > Managers.');

        return self::SUCCESS;
    }
}
