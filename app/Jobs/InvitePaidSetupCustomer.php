<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\InviteCustomerUser;
use Google\Ads\GoogleAds\V22\Enums\AccessRoleEnum\AccessRole;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Invite a paid setup customer as soon as their own Google Ads account exists. */
class InvitePaidSetupCustomer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $deleteWhenMissingModels = true;

    public $tries = 3;

    public $backoff = [60, 300];

    public function __construct(public Customer $customer) {}

    public static function dispatchIfReady(Customer $customer): void
    {
        if ($customer->isPaidSetupOnly() && ! $customer->is_sandbox && $customer->google_ads_customer_id) {
            self::dispatch($customer);
        }
    }

    public function handle(): void
    {
        $customer = $this->customer->fresh();
        if (! $customer || ! $customer->isPaidSetupOnly() || $customer->is_sandbox || ! $customer->google_ads_customer_id) {
            return;
        }

        $users = $customer->users;
        if ($users->isEmpty()) {
            throw new \RuntimeException("Paid setup customer {$customer->id} has no linked user to invite.");
        }

        $accountId = $customer->cleanGoogleCustomerId();
        $inviter = app()->makeWith(InviteCustomerUser::class, ['customer' => $customer]);
        $invited = [];
        $awaitingApproval = [];
        foreach ($users as $user) {
            $result = $inviter->execute($accountId, $user->email, AccessRole::ADMIN);
            if (! $result['success']) {
                throw new \RuntimeException("Google Ads administrator invitation failed for customer {$customer->id}: ".($result['error'] ?? 'unknown error'));
            }
            if ($result['approval_pending'] ?? false) {
                $awaitingApproval[] = $user->email;
            } else {
                $invited[] = $user->email;
            }
        }

        if ($invited) {
            AgentActivity::record(
                'onboarding',
                'google_ads_admin_invitation_sent',
                'Administrator access invited to the customer Google Ads account.',
                $customer->id,
                null,
                ['google_ads_customer_id' => $accountId, 'emails' => $invited]
            );
        }
        if ($awaitingApproval) {
            AgentActivity::record(
                'onboarding',
                'google_ads_admin_approval_pending',
                'A second Google Ads administrator must approve the access request before an invitation is sent.',
                $customer->id,
                null,
                ['google_ads_customer_id' => $accountId, 'emails' => $awaitingApproval]
            );
        }
    }
}
