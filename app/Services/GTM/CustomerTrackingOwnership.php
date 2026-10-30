<?php

namespace App\Services\GTM;

use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Customers\SetupGoogleAdsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Customer GTM ownership, verified through the platform credential without customer OAuth tokens. */
class CustomerTrackingOwnership
{
    public function __construct(private GTMContainerService $gtm) {}

    /** Cached presentation only. Call verify() before handing over or publishing. */
    public function status(Customer $customer): array
    {
        $identity = $this->identity($customer);
        $ownership = $customer->gtm_config['ownership'] ?? [];
        $binding = $identity && ($ownership['type'] ?? null) === 'customer'
            && ($ownership['subject'] ?? null) === $identity['subject']
            && strcasecmp($ownership['email'] ?? '', $identity['email']) === 0
            && ($customer->gtm_config['customer_uuid'] ?? null) === $customer->uuid
            && ($ownership['account_id'] ?? null) === (string) $customer->gtm_account_id
            && ($ownership['public_id'] ?? null) === $customer->gtm_container_id
            && ($customer->gtm_config['container_path'] ?? null) === 'accounts/'.($ownership['account_id'] ?? '').'/containers/'.($ownership['container_id'] ?? '')
            && ! empty($ownership['verified_at']);
        $connected = $binding && empty($ownership['verification_failed_at']);
        $status = $connected ? 'connected' : ($binding ? 'access_unverified' : (! $identity ? 'identity_required'
            : (($ownership['type'] ?? null) === 'customer' ? 'identity_changed'
                : ($customer->gtm_container_id ? 'legacy_platform_container' : 'connection_required'))));

        return ['status' => $status, 'connected' => (bool) $connected, 'email' => $identity['email'] ?? null,
            'account_id' => $ownership['account_id'] ?? null, 'container_id' => $ownership['container_id'] ?? null,
            'public_id' => $customer->gtm_container_id, 'verified_at' => $connected ? $ownership['verified_at'] : null,
            'requires_recheck' => ! $connected || ! $this->recent($ownership['verified_at'] ?? null),
            'platform_email' => app(GTMPlatformCredential::class)->email(),
            'message' => match ($status) {
                'connected' => 'Your Google account controls this tag container. Ownership is checked again before we publish tracking or complete handover.',
                'identity_required' => 'Confirm the Google account that will own your ads and tracking first.',
                'identity_changed' => 'Confirm access to this tag container again for your current Google account.',
                'access_unverified' => 'The latest Google access check failed. Confirm your account Administrator and SiteToSpend Publish permissions, then check again.',
                'legacy_platform_container' => 'This tracking container has not been verified as customer-controlled. Select a container in your own Google Tag Manager account; your existing snippet has not been changed.',
                default => 'Select a web container in your own Google Tag Manager account and grant SiteToSpend access to configure it.',
            }];
    }

    /** Verify the explicit selection. Merely detecting a public tag never assigns it. */
    public function connect(Customer $customer, string $accountId, string $containerId): array
    {
        return $this->connectVerified($customer, $accountId, $containerId, false);
    }

    private function connectVerified(Customer $customer, string $accountId, string $containerId, bool $preserveWorkspace): array
    {
        if (! preg_match('/^\d{1,20}$/D', $accountId) || ! preg_match('/^\d{1,20}$/D', $containerId)) {
            throw ValidationException::withMessages(['container_id' => 'Enter the numeric account and container IDs from Google Tag Manager, not the public GTM snippet ID.']);
        }
        if ($accountId === (string) config('services.gtm.platform_account_id')) {
            throw ValidationException::withMessages(['account_id' => 'Choose your own Google Tag Manager account. The shared SiteToSpend account cannot be handed over.']);
        }
        $current = $customer->fresh() ?? $customer;
        $identity = $this->identity($current);
        if (! $identity) {
            throw ValidationException::withMessages(['account_id' => 'Confirm your Google identity before connecting a tag container.']);
        }
        $platformEmail = app(GTMPlatformCredential::class)->email();
        if (! is_string($platformEmail) || ! filter_var($platformEmail, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['account_id' => 'The SiteToSpend tracking account is not configured for customer access yet. Contact support.']);
        }
        $path = "accounts/{$accountId}/containers/{$containerId}";

        try {
            return Cache::lock('gtm-container-binding:'.hash('sha256', $path), 120)->block(5, function () use ($current, $identity, $platformEmail, $accountId, $containerId, $path, $preserveWorkspace): array {
                $proof = $this->gtm->inspectContainerAccess($accountId, $containerId, ! $preserveWorkspace);
                if (! ($proof['success'] ?? false)) {
                    throw ValidationException::withMessages(['account_id' => $proof['error'] ?? 'Google Tag Manager ownership could not be checked.']);
                }
                $container = $proof['container'];
                $publicId = $container['publicId'] ?? null;
                if (($container['path'] ?? null) !== $path || (string) ($container['accountId'] ?? '') !== $accountId
                    || (string) ($container['containerId'] ?? '') !== $containerId || ! in_array('web', array_map('strtolower', $container['usageContext'] ?? []), true)
                    || ! is_string($publicId) || ! preg_match('/^GTM-[A-Z0-9]+$/D', $publicId)) {
                    throw ValidationException::withMessages(['container_id' => 'Google did not return the selected web container with matching account and container IDs.']);
                }
                $permissions = collect($proof['permissions']);
                $owner = $permissions->first(fn (array $permission) => strcasecmp($permission['emailAddress'] ?? '', $identity['email']) === 0);
                $platform = $permissions->first(fn (array $permission) => strcasecmp($permission['emailAddress'] ?? '', $platformEmail) === 0);
                if (! $owner || (string) ($owner['accountId'] ?? '') !== $accountId || ($owner['accountAccess']['permission'] ?? '') !== 'admin') {
                    throw ValidationException::withMessages(['account_id' => 'Your confirmed Google account must be an administrator of the selected Tag Manager account.']);
                }
                if (! $platform || (string) ($platform['accountId'] ?? '') !== $accountId || ($platform['accountAccess']['permission'] ?? '') !== 'admin'
                    || ! collect($platform['containerAccess'] ?? [])->contains(fn (array $access) => (string) ($access['containerId'] ?? '') === $containerId && ($access['permission'] ?? '') === 'publish')) {
                    throw ValidationException::withMessages(['container_id' => 'Grant the SiteToSpend Google account Administrator access to your Tag Manager account and Publish access to this container, then try again.']);
                }
                // Creating a GTM version can retire its workspace. A final access
                // check must preserve the selected workspace until exact-version
                // publication finishes; only explicit selection chooses another.
                $workspace = $preserveWorkspace ? ['workspaceId' => $current->gtm_workspace_id,
                    'path' => $path.'/workspaces/'.$current->gtm_workspace_id] : (collect($proof['workspaces'])->first(fn (array $workspace) => ($current->gtm_config['container_path'] ?? null) === $path
                    && (string) ($workspace['workspaceId'] ?? '') === (string) $current->gtm_workspace_id)
                    ?? collect($proof['workspaces'])->first(fn (array $workspace) => ($workspace['name'] ?? '') === 'Default Workspace')
                    ?? collect($proof['workspaces'])->first());
                if (! $workspace || ! preg_match('/^\d+$/D', (string) ($workspace['workspaceId'] ?? ''))
                    || ($workspace['path'] ?? null) !== $path.'/workspaces/'.$workspace['workspaceId']) {
                    throw ValidationException::withMessages(['container_id' => 'Google did not return a usable workspace for this container. Create a workspace in Google Tag Manager and try again.']);
                }

                return DB::transaction(function () use ($current, $identity, $container, $workspace, $publicId, $accountId, $containerId, $path): array {
                    // Unlike the remote-call cache lock, this lock cannot
                    // expire while the exclusivity decision is being saved.
                    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['gtm-container-binding:'.$path]);
                    $locked = Customer::whereKey($current->id)->lockForUpdate()->firstOrFail();
                    if ($this->identity($locked) !== $identity) {
                        throw ValidationException::withMessages(['account_id' => 'Your confirmed Google identity changed while we checked access. Try again.']);
                    }
                    if ($locked->gtm_container_id !== $current->gtm_container_id || ($locked->gtm_config['container_path'] ?? null) !== ($current->gtm_config['container_path'] ?? null)
                        || $locked->gtm_workspace_id !== $current->gtm_workspace_id) {
                        throw ValidationException::withMessages(['container_id' => 'Another tracking selection was saved while Google access was being checked. Review the current selection and try again.']);
                    }
                    if (self::assignedElsewhere($locked, $publicId, $path)) {
                        throw ValidationException::withMessages(['container_id' => 'This container is already assigned to another business. Select a separate container for this customer.']);
                    }
                    $same = $locked->gtm_container_id === $publicId && ($locked->gtm_config['container_path'] ?? null) === $path;
                    if (! $same && $locked->gtm_container_id) {
                        // Supersede in-flight requests too: they must not attach old
                        // website configuration to a newly selected container.
                        $strategies = Strategy::whereHas('campaign', fn ($query) => $query->where('customer_id', $locked->id))->lockForUpdate()->get();
                        foreach ($strategies as $strategy) {
                            $execution = $strategy->execution_result ?? [];
                            $goal = $execution['metadata']['setup_conversion_goal'] ?? null;
                            if (! is_array($goal) || ! in_array($goal['goal']['trigger'] ?? '', ['event', 'thank_you_url'], true)) {
                                continue;
                            }
                            $goal['managed_tag_container_path'] ??= $locked->gtm_config['container_path'] ?? null;
                            $goal['request_id'] = null;
                            $goal['status'] = 'needs_review';
                            $goal['message'] = 'Your tracking container changed. Review and configure the goal for this container, then install and test its snippet. Previously published tags have not been changed.';
                            $goal['issues'] = [['code' => 'tracking_container_changed', 'message' => $goal['message']]];
                            $execution['metadata']['setup_conversion_goal'] = $goal;
                            $strategy->update(['execution_result' => $execution]);
                        }
                    }
                    $config = $locked->gtm_config ?? [];
                    $handover = $locked->setup_handover ?? [];
                    if (! $same) {
                        unset($handover['completed_at']);
                    }
                    $config = [...$config, 'container_id' => $publicId, 'container_path' => $path,
                        'container_name' => $container['name'] ?? '', 'account_id' => $accountId, 'workspace_id' => (string) $workspace['workspaceId'],
                        'customer_uuid' => $locked->uuid, 'ownership' => ['type' => 'customer', 'email' => $identity['email'], 'subject' => $identity['subject'],
                            'verified_at' => now()->toIso8601String(), 'account_id' => $accountId, 'container_id' => $containerId, 'public_id' => $publicId]];
                    $locked->update(['gtm_container_id' => $publicId, 'gtm_account_id' => $accountId,
                        'gtm_workspace_id' => (string) $workspace['workspaceId'], 'gtm_config' => $config,
                        'gtm_installed' => $same ? $locked->gtm_installed : false,
                        'gtm_last_verified' => $same ? $locked->gtm_last_verified : null,
                        'conversion_tracking_verified_at' => $same ? $locked->conversion_tracking_verified_at : null,
                        'setup_handover' => $handover,
                        'handover_at' => $same ? $locked->handover_at : null]);
                    $current->setRawAttributes($locked->getAttributes(), true);

                    return $this->status($locked);
                });
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            throw ValidationException::withMessages(['container_id' => 'This tag container is already being checked. Wait for the current check to finish, then try again.']);
        }
    }

    /** Fresh remote proof; callers must not use cached presentation as authorization. */
    public function verify(Customer $customer): array
    {
        $current = $customer->fresh() ?? $customer;
        $ownership = $current->gtm_config['ownership'] ?? [];
        if (! in_array($this->status($current)['status'], ['connected', 'access_unverified'], true)) {
            throw ValidationException::withMessages(['container_id' => 'Connect a customer-controlled tag container before publishing website tracking.']);
        }

        try {
            return $this->connectVerified($current, $ownership['account_id'], $ownership['container_id'], true);
        } catch (ValidationException $e) {
            DB::transaction(function () use ($current, $ownership): void {
                $locked = Customer::whereKey($current->id)->lockForUpdate()->first();
                if ($locked && ($locked->gtm_config['ownership'] ?? null) === $ownership) {
                    $config = $locked->gtm_config;
                    $config['ownership']['verification_failed_at'] = now()->toIso8601String();
                    $handover = $locked->setup_handover ?? [];
                    unset($handover['completed_at']);
                    $locked->update(['gtm_config' => $config, 'handover_at' => null, 'setup_handover' => $handover]);
                }
            });
            throw $e;
        }
    }

    public static function assignedElsewhere(Customer $customer, string $publicId, string $path): bool
    {
        // Include archived businesses: domain equality is never ownership proof.
        return Customer::withoutGlobalScopes()->whereKeyNot($customer->id)
            ->where(fn ($query) => $query->where('gtm_container_id', $publicId)->orWhere('gtm_config->container_path', $path))->exists();
    }

    private function identity(Customer $customer): ?array
    {
        $identity = SetupGoogleAdsAccess::identity($customer);
        if (! is_string($identity['email'] ?? null) || ! filter_var($identity['email'], FILTER_VALIDATE_EMAIL)
            || ! is_string($identity['subject'] ?? null) || $identity['subject'] === '' || empty($identity['verified_at'])) {
            return null;
        }

        return $identity;
    }

    private function recent(mixed $value): bool
    {
        try {
            return is_string($value) && CarbonImmutable::parse($value)->between(now()->subMinutes(15), now());
        } catch (\Throwable) {
            return false;
        }
    }
}
