<?php

namespace App\Services\Customers;

use App\Mail\HandoverComplete;
use App\Models\Customer;
use App\Services\ActivityLogger;
use App\Services\GTM\CustomerTrackingOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/** Complete account access handover; installation, event testing and billing remain distinct. */
class HandOverAccount
{
    public function handOver(Customer $customer): array
    {
        $result = ['handed_over' => false, 'invited' => [], 'failed' => []];
        if ($customer->service_type !== 'setup_only') {
            return $result + ['reason' => 'not_setup_only'];
        }
        if ($customer->handover_at && ! empty($customer->setup_handover['completed_at'])) {
            return $result + ['reason' => 'already_handed_over'];
        }
        if (! $customer->isPaidSetupOnly() || $customer->is_sandbox) {
            return $result + ['reason' => 'not_paid'];
        }
        if (! $customer->google_ads_customer_id) {
            return $result + ['reason' => 'no_account'];
        }

        // Invite the selected, verified Google identity early; sending is never acceptance.
        $access = app(SetupGoogleAdsAccess::class)->reconcile($customer, inviteIfMissing: true);
        if (($access['status'] ?? null) !== 'accepted') {
            return $result + ['reason' => $access['status'] ?? 'unverified',
                'awaiting_approval' => isset($access['email']) ? [$access['email']] : []];
        }
        $customer = $customer->fresh();
        if (! $this->campaignVerified($customer)) {
            return $result + ['reason' => 'campaign_pending'];
        }

        // A container inside our shared account is not customer ownership.
        try {
            $ownership = app(CustomerTrackingOwnership::class)->verify($customer);
        } catch (ValidationException $e) {
            return $result + ['reason' => 'tracking_pending'];
        }
        if (! ($ownership['connected'] ?? false)) {
            return $result + ['reason' => 'tracking_pending'];
        }

        $completed = DB::transaction(function () use ($customer, $access) {
            $locked = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if (($locked->handover_at && ! empty($locked->setup_handover['completed_at'])) || ! $locked->isPaidSetupOnly() || $locked->is_sandbox
                || SetupGoogleAdsAccess::status($locked) != $access || ! $this->campaignVerified($locked)
                || ! (app(CustomerTrackingOwnership::class)->status($locked)['connected'] ?? false)) {
                return false;
            }
            $state = $locked->setup_handover ?? [];
            $state['completed_at'] = now()->toIso8601String();
            $locked->forceFill(['handover_at' => now(), 'setup_handover' => $state])->save();

            return true;
        });
        if (! $completed) {
            return $result + ['reason' => 'state_changed'];
        }

        foreach ($customer->users as $user) {
            Mail::to($user->email)->send(new HandoverComplete($customer->fresh(), [$access['email']]));
        }
        ActivityLogger::customer('handover_completed', $customer);

        return ['handed_over' => true, 'invited' => [$access['email']], 'failed' => []];
    }

    private function campaignVerified(Customer $customer): bool
    {
        $strategies = $customer->campaigns()->oldest('id')->first()?->strategies;

        return $strategies?->isNotEmpty() && $strategies->every(fn ($strategy) => $strategy->deployment_status === 'verified');
    }
}
