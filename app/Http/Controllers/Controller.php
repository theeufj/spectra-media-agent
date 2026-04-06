<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function getActiveCustomer(Request $request): ?Customer
    {
        $user = $request->user();
        $customerId = session('active_customer_id');

        if ($customerId) {
            return Customer::where('id', $customerId)
                ->whereHas('users', fn($q) => $q->where('user_id', $user->id))
                ->first();
        }

        return $user->customers()->first();
    }
}
