<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    protected function getActiveCustomer(Request $request): ?Customer
    {
        $user = $request->user();
        $customerId = session('active_customer_id');

        if ($customerId) {
            $active = Customer::where('id', $customerId)
                ->where('is_sandbox', false)
                ->whereHas('users', fn ($q) => $q->where('user_id', $user->id))
                ->first();

            if ($active) {
                return $active;
            }

            /*
             * The session points at a customer this user cannot reach.
             *
             * It happens whenever the account is deleted, the user is removed
             * from it, or a session outlives the row — and every caller treated
             * it as fatal. Fifteen of them did `customers()->findOrFail(
             * session('active_customer_id'))`, which is a hard 404: the brand
             * guidelines page, the campaign wizard, deployment, and the
             * dashboard itself, so even the "Back to dashboard" button on the
             * 404 landed on another 404. A stale id is not a missing page.
             *
             * Forget it and fall through to whatever they do have. Re-stamped
             * below so the next request is clean rather than repeating this.
             */
            session()->forget('active_customer_id');
        }

        $fallback = $user->customers()->where('is_sandbox', false)->first();

        if ($fallback) {
            session(['active_customer_id' => $fallback->id]);
        }

        return $fallback;
    }
}
