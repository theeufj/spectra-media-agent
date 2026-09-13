<?php

namespace App\Services\Customers;

use App\Mail\HandoverComplete;
use App\Models\Customer;
use App\Services\ActivityLogger;
use App\Services\GoogleAds\CommonServices\InviteCustomerUser;
use Illuminate\Support\Facades\Mail;

/**
 * Close a one-time setup engagement: invite them in, stamp it, send the keys.
 *
 * Lived inside Admin\CustomerController, which made it something a person had to
 * remember to press. That is the wrong shape for the last step of an engagement
 * that is otherwise automatic — a customer who paid US$999 and whose account,
 * tracking, campaign and ads are all built should not be waiting on an admin
 * noticing. Now both callers share it: the admin button, and the job that fires
 * when the build finishes.
 */
class HandOverAccount
{
    /**
     * @return array{handed_over: bool, invited: list<string>, failed: array<string, string>, reason?: string}
     */
    public function handOver(Customer $customer): array
    {
        if ($customer->service_type !== 'setup_only') {
            return ['handed_over' => false, 'invited' => [], 'failed' => [], 'reason' => 'not_setup_only'];
        }

        if ($customer->handover_at) {
            return ['handed_over' => false, 'invited' => [], 'failed' => [], 'reason' => 'already_handed_over'];
        }

        if (empty($customer->google_ads_customer_id)) {
            // Nothing to hand over yet. Not an error — the account is created on
            // payment and the caller may simply be early.
            return ['handed_over' => false, 'invited' => [], 'failed' => [], 'reason' => 'no_account'];
        }

        /*
           The keys, before the email that says they are yours.

           The account is a sub-account under Spectra's MCC, so it belongs to us
           until a Google login is attached to it. Admin, because adding billing
           is the first thing the handover email asks them to do and no lesser
           role can.
        */
        $inviter = app()->makeWith(InviteCustomerUser::class, ['customer' => $customer]);

        $invited = [];
        $failed = [];

        foreach ($customer->users as $user) {
            $result = $inviter->execute($customer->cleanGoogleCustomerId(), $user->email);

            $result['success'] ? $invited[] = $user->email : $failed[$user->email] = $result['error'] ?? 'unknown';
        }

        if ($failed) {
            /*
               Do not stamp the handover.

               A customer who cannot open the account has not been handed
               anything, and a handover_at that says otherwise closes the
               engagement in our records while leaving them locked out of what
               they paid for — with nothing left to chase it.
            */
            report(new \RuntimeException(
                "Handover blocked for customer {$customer->id}: ".json_encode($failed)
            ));

            return ['handed_over' => false, 'invited' => $invited, 'failed' => $failed, 'reason' => 'invite_failed'];
        }

        $customer->forceFill(['handover_at' => now()])->save();

        foreach ($customer->users as $user) {
            Mail::to($user->email)->send(new HandoverComplete($customer, $invited));
        }

        ActivityLogger::customer('handover_completed', $customer);

        return ['handed_over' => true, 'invited' => $invited, 'failed' => []];
    }
}
