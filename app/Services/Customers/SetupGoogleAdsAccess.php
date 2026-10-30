<?php

namespace App\Services\Customers;

use App\Models\AgentActivity;
use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\InviteCustomerUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Customer identity is verified separately; all Ads calls still use the platform MCC. */
class SetupGoogleAdsAccess
{
    public static function identity(Customer $customer): ?array
    {
        $identity = $customer->setup_handover['google_identity'] ?? null;
        if (! is_array($identity) || empty($identity['email']) || empty($identity['subject'])
            || empty($identity['verified_at']) || empty($identity['verified_by_user_id'])
            || ! $customer->users()->whereKey($identity['verified_by_user_id'])->exists()) {
            return null;
        }

        return $identity;
    }

    /** Cached evidence is usable only for the same account and confirmed identity. */
    public static function status(Customer $customer): array
    {
        $identity = self::identity($customer);
        if (! $identity) {
            return ['status' => 'identity_required'];
        }
        $access = $customer->setup_handover['ads_access'] ?? [];
        if (($access['account_id'] ?? null) !== $customer->cleanGoogleCustomerId()
            || ($access['subject'] ?? null) !== $identity['subject']
            || ($access['email'] ?? null) !== $identity['email']) {
            return ['status' => 'unverified', 'email' => $identity['email']];
        }

        return $access;
    }

    public function reconcile(Customer $customer, bool $inviteIfMissing = false): array
    {
        $lock = Cache::lock('setup-ads-access:'.$customer->id, 120);
        if (! $lock->get()) {
            $current = $customer->fresh();

            return ['status' => $current ? 'checking' : 'unavailable'];
        }

        try {
            $customer = $customer->fresh();
            if (! $customer || ! $customer->isPaidSetupOnly() || $customer->is_sandbox || ! $customer->google_ads_customer_id) {
                return ['status' => 'unavailable'];
            }
            $identity = self::identity($customer);
            if (! $identity) {
                return ['status' => 'identity_required'];
            }
            $account = $customer->cleanGoogleCustomerId();
            $old = self::status($customer);
            $inviter = app()->makeWith(InviteCustomerUser::class, ['customer' => $customer]);
            try {
                $status = $inviter->accessStatus($account, $identity['email']);
                // Google review requests do not appear in either access resource yet.
                $receipt = $this->invitationReceipt($old);
                if ($status === 'not_invited' && $receipt) {
                    $status = $receipt['status'];
                } elseif ($status === 'not_invited' && $inviteIfMissing) {
                    $evidence = DB::transaction(function () use ($customer, $identity, $account, $inviter, $old) {
                        $locked = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                        $this->assertCurrentBinding($locked, $identity, $account);
                        $latest = self::status($locked);
                        // A slow Google call can outlive the Redis lock. Reuse
                        // the receipt committed by the request that held this
                        // row first, rather than granting the same access twice.
                        if ($latest != $old) {
                            return $latest;
                        }
                        $result = $inviter->execute($account, $identity['email']);
                        if (! $result['success']) {
                            throw new \RuntimeException('Google Ads invitation failed: '.($result['error'] ?? 'unknown error'));
                        }
                        $status = ($result['approval_pending'] ?? false) ? 'approval_pending' : 'invitation_pending';
                        $evidence = $this->evidence($identity, $account, $status);
                        // Commit the mutation receipt before releasing the row
                        // lock shared with identity changes and other invites.
                        $this->save($locked, $identity, $account, $evidence);

                        return $evidence;
                    });
                    $status = $evidence['status'];
                }
                $evidence ??= $this->evidence($identity, $account, $status, $old);
                $evidence = $this->save($customer, $identity, $account, $evidence, $old);
                $status = $evidence['status'];
                if (($old['status'] ?? null) !== $status) {
                    $action = match ($status) {
                        'accepted' => 'google_ads_admin_access_verified',
                        'invitation_pending' => 'google_ads_admin_invitation_sent',
                        'approval_pending' => 'google_ads_admin_approval_pending',
                        default => null,
                    };
                    if ($action) {
                        AgentActivity::record('onboarding', $action, match ($status) {
                            'accepted' => 'Google confirmed administrator access to the customer account.',
                            'invitation_pending' => 'Administrator invitation sent; customer acceptance is still required.',
                            default => 'A second Google administrator must approve the request before an invitation can be sent.',
                        }, $customer->id, null, ['google_ads_customer_id' => $account, 'emails' => [$identity['email']]]);
                    }
                }

                return $evidence;
            } catch (\Throwable $e) {
                $receipt = $this->invitationReceipt($old);
                $this->save($customer, $identity, $account, [
                    'status' => 'error', 'email' => $identity['email'], 'subject' => $identity['subject'],
                    'account_id' => $account, 'checked_at' => now()->toIso8601String(),
                    ...($receipt ? ['last_invitation' => $receipt] : []),
                ], $old);
                throw $e;
            }
        } finally {
            $lock->release();
        }
    }

    /** Preserve a pending review across read failures; it is not accepted access. */
    private function invitationReceipt(array $evidence): ?array
    {
        $receipt = $evidence['last_invitation'] ?? $evidence;
        if (! in_array($receipt['status'] ?? null, ['approval_pending', 'invitation_pending'], true)
            || ! is_string($receipt['requested_at'] ?? null)) {
            return null;
        }
        try {
            $requested = Carbon::parse($receipt['requested_at']);
            if ($requested->isFuture() || $requested->lte(now()->subDays(20))) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return ['status' => $receipt['status'], 'requested_at' => $receipt['requested_at']];
    }

    private function evidence(array $identity, string $account, string $status, array $previous = []): array
    {
        $receipt = $this->invitationReceipt($previous);
        $requestedAt = ($receipt['status'] ?? null) === $status ? $receipt['requested_at']
            : (($previous['status'] ?? null) === $status ? ($previous['requested_at'] ?? now()->toIso8601String()) : now()->toIso8601String());

        return ['status' => $status, 'email' => $identity['email'], 'subject' => $identity['subject'],
            'account_id' => $account, 'checked_at' => now()->toIso8601String(), 'requested_at' => $requestedAt,
            ...(in_array($status, ['approval_pending', 'invitation_pending'], true)
                ? ['last_invitation' => ['status' => $status, 'requested_at' => $requestedAt]] : []),
        ];
    }

    private function assertCurrentBinding(Customer $customer, array $identity, string $account): void
    {
        $fresh = $customer->fresh();
        if (! $fresh || $fresh->cleanGoogleCustomerId() !== $account || self::identity($fresh) != $identity
            || ! $fresh->isPaidSetupOnly() || $fresh->is_sandbox) {
            throw new \RuntimeException('Google identity or account changed. Check access again.');
        }
    }

    private function save(Customer $customer, array $identity, string $account, array $evidence, ?array $expected = null): array
    {
        return DB::transaction(function () use ($customer, $identity, $account, $evidence, $expected) {
            $locked = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentBinding($locked, $identity, $account);
            $latest = self::status($locked);
            if ($expected !== null && $latest != $expected) {
                return $latest;
            }
            $state = $locked->setup_handover ?? [];
            $state['ads_access'] = $evidence;
            $locked->forceFill(['setup_handover' => $state])->save();

            return $evidence;
        });
    }
}
