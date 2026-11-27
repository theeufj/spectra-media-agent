<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBusinessWorkspace
{
    public function create(User $user, array $attributes, bool $quickStart = false): Customer
    {
        return DB::transaction(function () use ($user, $attributes, $quickStart) {
            $user = User::lockForUpdate()->findOrFail($user->id);
            if ($quickStart) {
                $existing = $user->customers()->whereRaw('lower(website) = ?', [mb_strtolower($attributes['website'])])->first();
                if ($existing) {
                    return $existing;
                }
            }

            $slug = $user->resolveCurrentPlan()->slug ?? 'free';
            $max = $slug === 'agency' ? 10 : 1;
            $starterWorkspace = $user->customers()->whereHas('plan', fn ($q) => $q->where('slug', 'starter'))->exists();
            if ((! $quickStart || $slug === 'starter' || $starterWorkspace) && $user->customers()->count() >= $max) {
                $label = $starterWorkspace && $slug === 'free' ? 'Starter' : ucfirst($slug);

                throw ValidationException::withMessages([
                    $quickStart ? 'url' : 'name' => "{$label} plan is limited to {$max} customer account".($max > 1 ? 's' : '').'. Upgrade your plan to add more.',
                ]);
            }

            $tenantKey = $user->tenant_key;
            if ($tenantKey !== null && Tenant::singlePlan($tenantKey)) {
                $attributes['tenant_key'] = $tenantKey;
            }

            $tenantKey = $attributes['tenant_key'] ?? null;
            if ($tenantKey !== null && Tenant::singlePlan($tenantKey) && ($attributes['service_type'] ?? null) === 'setup_only') {
                throw ValidationException::withMessages(['service_type' => 'Choose managed Google Ads to continue.']);
            }

            $customer = Customer::create($attributes);
            $user->customers()->attach($customer->id, ['role' => 'owner']);
            if ($tenantKey !== null && $user->tenant_key === null) {
                $user->forceFill(['tenant_key' => $tenantKey])->save();
            }

            return $customer;
        });
    }
}
