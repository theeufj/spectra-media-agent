<?php

namespace App\Services\Billing;

use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Plan;
use App\Services\DeploymentService;
use App\Services\FacebookAds\BusinessManagerService;
use App\Services\GoogleAds\BillingSetupService;

/** Provider proof for the exact ad account the customer is about to purchase or fund. */
class PlatformFunding
{
    public function assess(Customer $customer, string $platform): array
    {
        $proof = $this->inspectAccount($customer, $platform);
        $owner = match ($proof['code']) {
            'ready' => null,
            'facebook_page_or_account_missing', 'facebook_page_permission_missing', 'website_access_required' => 'Customer',
            default => 'Site to Spend',
        };
        $account = (string) $proof['account'];
        \Illuminate\Support\Facades\DB::table('channel_account_readiness')->upsert([[
            'customer_id' => $customer->id, 'platform' => $proof['platform'], 'account_id' => $account,
            'payer' => $proof['payer'], 'currency' => $proof['currency'], 'permissions' => $proof['permissions'] ?? ($proof['ready'] ? 'verified' : 'unverified'),
            'funding' => $proof['funding'] ?? ($proof['ready'] ? 'verified' : 'unverified'), 'tracking' => $proof['tracking'] ?? 'awaiting_event_test',
            'blocker' => $proof['ready'] ? null : $proof['code'], 'owner' => $owner,
            'next_action' => $proof['ready'] ? null : $proof['message'], 'checked_at' => now(),
        ]], ['customer_id', 'platform', 'account_id']);

        return $proof + ['owner' => $owner];
    }

    private function inspectAccount(Customer $customer, string $platform): array
    {
        $platform = DeploymentService::normalizePlatform($platform);
        $account = match ($platform) {
            'google' => $customer->cleanGoogleCustomerId(),
            'facebook' => preg_replace('/^act_/', '', (string) $customer->facebook_ads_account_id),
            default => '',
        };
        $result = ['ready' => false, 'platform' => $platform, 'payer' => 'unknown', 'account' => $account,
            'currency' => $customer->billingCurrency(), 'code' => 'account_pending', 'message' => 'Your dedicated ad account is waiting for setup.'];
        if ($customer->is_sandbox || ! in_array($platform, ['google', 'facebook'], true) || ! EnabledPlatform::isEnabled($platform)) {
            return [...$result, 'code' => 'channel_unavailable', 'message' => 'This channel is currently unavailable.'];
        }
        if (! ctype_digit((string) $account)) {
            return $result;
        }
        $field = $platform === 'google' ? 'google_ads_customer_id' : 'facebook_ads_account_id';
        if (Customer::query()->whereKeyNot($customer->id)->whereRaw("replace(replace({$field}, '-', ''), 'act_', '') = ?", [$account])->exists()) {
            return [...$result, 'code' => 'account_shared', 'message' => 'This ad account is assigned to another workspace.'];
        }
        try {
            if ($platform === 'google') {
                $proof = app(BillingSetupService::class, ['customer' => $customer])->payerEvidence($account);
            } else {
                if (! $customer->facebook_bm_owned || ! $customer->facebook_page_id) {
                    return [...$result, 'code' => 'facebook_page_or_account_missing', 'message' => 'Link your Facebook Page to its dedicated business-owned ad account.'];
                }
                $proof = null;
                $issue = app(BusinessManagerService::class)->launchAccountIssue($account, $proof);
                if ($issue) {
                    return [...$result, ...$issue];
                }
            }
        } catch (\Throwable $e) {
            report($e);
            $proof = null;
        }
        if (! $proof || ($proof['currency'] ?? null) !== $result['currency'] || blank($proof['source'] ?? null)) {
            return [...$result, 'code' => 'payer_unverified', 'message' => 'The ad platform must confirm an active billing source, available account budget and matching advertising currency.'];
        }
        $result['permissions'] = $platform === 'google' ? 'verified' : 'ad_account_verified';
        $agencySources = config("billing.agency_funding_sources.{$platform}", []);
        $payer = in_array($proof['source'], $agencySources, true) ? 'agency' : null;
        if (! $payer && $platform === 'google' && $agencySources !== []
            && $customer->google_ads_link_status === 'active' && $customer->grandfathered_google_payer_account === $account) {
            $payer = 'customer';
        }
        if (! $payer) {
            return [...$result, 'code' => 'agency_payer_required', 'message' => 'The dedicated ad account is waiting for its verified Site to Spend billing source.'];
        }
        $currencies = new \Money\Currencies\ISOCurrencies;
        $currency = new \Money\Currency($result['currency']);
        if ($payer === 'agency' && (! $currencies->contains($currency) || $currencies->subunitFor($currency) !== 2)) {
            // The wallet and card gateway currently collect supported two-decimal currencies 1:1.
            return [...$result, 'code' => 'currency_unsupported', 'message' => 'Managed prepaid ad spend is unavailable in this account currency.'];
        }

        $result['payer'] = $payer;
        $result['funding'] = 'verified';
        if ($platform === 'facebook') {
            try {
                $access = new \App\Services\FacebookAds\PixelService($customer);
                if (! $access->canAdvertisePage((string) $customer->facebook_page_id)) {
                    return [...$result, 'code' => 'facebook_page_permission_missing', 'message' => 'Grant Site to Spend advertising access on the selected Facebook Page.'];
                }
                $result['permissions'] = 'verified';
                if (! $customer->facebook_pixel_id || ! $access->isLinked((string) $customer->facebook_pixel_id, $account)) {
                    return [...$result, 'code' => 'facebook_dataset_unverified', 'message' => 'Site to Spend needs to verify the selected dataset on this ad account.'];
                }
                $result['tracking'] = 'dataset_link_verified';
            } catch (\Throwable $e) {
                report($e);

                return [...$result, 'code' => 'facebook_asset_check_pending', 'message' => 'Site to Spend will recheck Page and dataset access.'];
            }
        }

        return [...$result, 'ready' => true, 'payer' => $payer, 'code' => 'ready',
            'message' => $payer === 'agency' ? 'Site to Spend manages your ad payments.' : 'Your existing ad account pays the provider directly.'];
    }

    public function forPlan(Customer $customer, Plan $plan, array $requested): array
    {
        $allowed = array_intersect(['google', 'facebook'], Plan::platformsFor($plan->slug));
        $channels = [];
        foreach (array_unique($requested) as $platform) {
            if (in_array($platform, $allowed, true)) {
                $channels[$platform] = $this->assess($customer, $platform);
            }
        }

        return ['ready' => in_array(true, array_column($channels, 'ready'), true), 'channels' => $channels];
    }
}
