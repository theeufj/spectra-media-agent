<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Customers\HandOverAccount;
use App\Services\Customers\SetupGoogleAdsAccess;

class SetupAccountAccessController extends Controller
{
    public function verify(Customer $customer, HandOverAccount $handover)
    {
        $this->authorize('update', $customer);
        abort_unless($customer->service_type === 'setup_only', 404);
        try {
            if ($customer->handover_at && ! empty($customer->setup_handover['completed_at'])) {
                app(SetupGoogleAdsAccess::class)->reconcile($customer);
            } else {
                $handover->handOver($customer);
            }
            $status = SetupGoogleAdsAccess::status($customer->fresh())['status'];
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Google account access could not be checked. Your ads and budget have not changed. Please try again.');
        }

        return back()->with('success', match ($status) {
            'accepted' => 'Google confirmed your administrator access to this Ads account.',
            'invitation_pending' => 'Your invitation is waiting for you to accept it in Google Ads. Then check access again.',
            'approval_pending' => 'Google requires another administrator to approve this invitation request. No invitation has been sent yet.',
            'identity_required' => 'Confirm the Google account you want to use first.',
            default => 'Administrator access has not been confirmed yet.',
        });
    }
}
