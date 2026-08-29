<?php

namespace App\Jobs;

use App\Mail\GoogleAdsLinkInvitation;
use App\Models\AgentActivity;
use App\Models\Customer;
use App\Models\MccAccount;
use App\Services\GoogleAds\CreateCustomerClientLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Invites a customer's existing Google Ads account to link to Spectra's manager
 * account, then emails them to accept it.
 *
 * This is the path around the account-creation gate. Google will not let a
 * manager account create client accounts through the API until it has managed
 * roughly US$1,000 of spend — but linking an account the customer already owns
 * carries no such threshold. Customers who arrive with an account can therefore
 * be onboarded immediately rather than waiting for the gate to clear.
 *
 * The invitation lands inside the customer's own Google Ads interface; the email
 * only tells them it is there. Nothing is linked until they accept, which is the
 * correct shape — access to an advertising account should require the owner to
 * say yes.
 */
class SendGoogleAdsLinkInvitation implements ShouldQueue
{
    /**
     * A soft-deleted customer/campaign mid-queue means the work is moot —
     * discard quietly instead of filling failed_jobs with ModelNotFound.
     */
    public $deleteWhenMissingModels = true;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(protected Customer $customer) {}

    public function handle(): void
    {
        $customer = $this->customer->fresh();

        if (! $customer) {
            return;
        }

        $clientId = preg_replace('/[^0-9]/', '', (string) $customer->google_ads_customer_id);

        if (strlen($clientId) !== 10) {
            Log::warning('SendGoogleAdsLinkInvitation: not a valid Google Ads customer id', [
                'customer_id' => $customer->id,
                'value' => $customer->google_ads_customer_id,
            ]);

            return;
        }

        // Already linked, or already invited and waiting.
        if (in_array($customer->google_ads_link_status, ['active', 'pending'], true)) {
            return;
        }

        $mcc = MccAccount::getActive();

        if (! $mcc) {
            Log::error('SendGoogleAdsLinkInvitation: no active MCC account configured');

            return;
        }

        $managerId = preg_replace('/[^0-9]/', '', $mcc->google_customer_id);

        if ($clientId === $managerId) {
            Log::warning('SendGoogleAdsLinkInvitation: refusing to link the manager account to itself', [
                'customer_id' => $customer->id,
            ]);

            return;
        }

        try {
            $result = (new CreateCustomerClientLink($customer))($managerId, $clientId);
        } catch (\Throwable $e) {
            // An account already under this manager is not a failure — it is the
            // answer the customer wanted. Google says ALREADY_MANAGED_IN_HIERARCHY
            // and the old code treated it as an error, leaving link status null
            // and the customer with no feedback at all: the one case where
            // everything is fine looked identical to everything being broken.
            if (str_contains($e->getMessage(), 'ALREADY_MANAGED_IN_HIERARCHY')) {
                $customer->update([
                    'google_ads_link_status' => 'active',
                    'google_ads_link_confirmed_at' => now(),
                    'google_ads_manager_customer_id' => $managerId,
                ]);

                AgentActivity::record(
                    'onboarding',
                    'google_ads_account_already_linked',
                    'Google Ads account '.$clientId.' was already managed for "'.$customer->name.'"',
                    $customer->id,
                    null,
                    ['client_account' => $clientId, 'manager_account' => $managerId]
                );

                Log::info('SendGoogleAdsLinkInvitation: account already managed, marked active', [
                    'customer_id' => $customer->id,
                    'client_account' => $clientId,
                ]);

                return;
            }

            // Anything else leaves a record the customer can be told about,
            // rather than a status that stays null for reasons only the log
            // knows.
            $customer->update([
                'google_ads_link_status' => 'failed',
                'google_ads_link_requested_at' => now(),
            ]);

            // Surface in the admin dashboard: a customer sitting unlinked is a
            // customer who cannot be advertised for.
            report($e);
            Log::error('SendGoogleAdsLinkInvitation: invitation failed', [
                'customer_id' => $customer->id,
                'client_account' => $clientId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        if (! $result) {
            Log::error('SendGoogleAdsLinkInvitation: Google returned no link resource', [
                'customer_id' => $customer->id,
            ]);

            return;
        }

        $customer->update([
            'google_ads_link_status' => 'pending',
            'google_ads_link_requested_at' => now(),
            'google_ads_link_resource_name' => $result['resourceName'],
        ]);

        foreach ($customer->users as $user) {
            Mail::to($user->email)->send(
                (new GoogleAdsLinkInvitation($customer, $clientId))->withTenant($customer->tenant_key ?? null)
            );
        }

        Log::info('SendGoogleAdsLinkInvitation: invitation sent', [
            'customer_id' => $customer->id,
            'client_account' => $clientId,
            'resource' => $result['resourceName'],
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendGoogleAdsLinkInvitation failed: '.$exception->getMessage(), [
            'customer_id' => $this->customer->id,
        ]);
    }
}
