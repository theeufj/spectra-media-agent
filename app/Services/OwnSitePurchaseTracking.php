<?php

namespace App\Services;

use App\Jobs\RecordSiteGoogleConversion;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Models\Strategy;
use App\Models\User;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\GetConversionActionDetails;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use App\Services\GoogleAds\WebsiteConversionActions;
use App\Services\Onboarding\SetupConversionGoal;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Actual paid receipts to an explicitly installed own-site website action, never synthetic conversions. */
class OwnSitePurchaseTracking
{
    public const SETTING = 'own_site.purchase_tracking';

    /** Only Stripe retrieval or its signed webhook may supply this session. */
    public function recordSetupPurchase(User $user, Customer $customer, array $session, ?int $completedAt = null): ?SpectraConversionEvent
    {
        $amount = $session['amount_total'] ?? null;
        $created = $session['created'] ?? null;
        $completedAt ??= data_get($session, 'payment_intent.latest_charge.created');
        if ((! app()->environment('testing') && ($session['livemode'] ?? false) !== true)
            || ($session['mode'] ?? null) !== 'payment' || ($session['status'] ?? null) !== 'complete'
            || ($session['payment_status'] ?? null) !== 'paid' || ! is_int($amount) || $amount <= 0
            || $amount > (int) config('services.stripe.setup_fee_usd_cents', 99900)
            || strtoupper($session['currency'] ?? '') !== 'USD'
            || ! is_string($session['id'] ?? null) || ! preg_match('/^cs_[A-Za-z0-9_]+$/', $session['id'])
            || ! is_int($created) || $created <= 0 || $created > now()->timestamp
            || ! is_int($completedAt) || $completedAt < $created || $completedAt > now()->timestamp
            || ($session['customer'] ?? null) !== $user->stripe_id || ! $user->stripe_id
            || ($session['client_reference_id'] ?? null) !== 'setup-fee-'.$customer->id
            || data_get($session, 'metadata.purpose') !== 'setup_fee'
            || data_get($session, 'metadata.customer_id') !== (string) $customer->id
            || data_get($session, 'metadata.user_id') !== (string) $user->id
            || ! $user->customers()->whereKey($customer->id)->exists()) {
            return null;
        }

        $identifiers = $user->googleAdIdentifiers();
        $serverRoute = $identifiers !== null
            && preg_match('/\A[A-Za-z0-9._~+\/=\-]{1,255}\z/D', reset($identifiers)) === 1;
        $destination = $serverRoute ? RecordSiteGoogleConversion::setupPurchaseDestination() : null;
        $event = SpectraConversionEvent::firstOrCreate(['deduplication_key' => 'paid_setup:'.$session['id']], [
            'event' => 'paid_setup', 'user_id' => $user->id, 'mode' => $serverRoute ? 'server_google' : 'verified_purchase',
            'value' => intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT),
            'currency' => 'USD', 'occurred_at' => CarbonImmutable::createFromTimestampUTC($completedAt),
            'ad_identifiers' => $identifiers, 'gclid' => $user->googleClickId(),
            'uploaded_to_google' => false, 'google_conversion_resource' => $destination,
            'upload_error' => $serverRoute && ! $destination ? 'The canonical Purchase destination is unavailable or belongs to another Google account.' : null,
        ]);
        // Destination ownership is fixed on creation. A webhook replay cannot
        // reroute legacy browser receipts or send one purchase to both accounts.
        // Native browser delivery only selects paid_setup.mode=verified_purchase,
        // so queued or failed server deliveries cannot fall back to that tag.
        if ($event->mode === 'server_google' && $event->ad_identifiers && ! $event->uploaded_to_google) {
            RecordSiteGoogleConversion::dispatch($user, 'paid_setup', $event->occurred_at, $event->id)->afterCommit();
        }

        return $event;
    }

    /** Operator installation only. API calls are reads; no campaign or action is changed. */
    public function bindReviewedGoal(Strategy $strategy): array
    {
        if (auth()->check() && ! auth()->user()->hasRole('admin')) {
            throw new \LogicException('Only an operator can install the own-site purchase destination.');
        }
        $strategy->refresh()->load('campaign.customer');
        $customer = $strategy->campaign->customer;
        $configuration = SetupConversionGoal::configurationFor($strategy);
        $actionResource = $configuration['action_resource'] ?? '';
        $resource = $this->campaignResource($strategy);
        if (($configuration['status'] ?? null) !== 'configured' || ($configuration['goal']['objective'] ?? null) !== 'purchase'
            || ! $this->origin($customer->website) || $this->origin($customer->website) !== $this->origin(config('app.url'))
            || ! $resource || ($configuration['managed_tag_may_exist'] ?? false)) {
            throw new \LogicException('A verified own-site purchase goal without a competing managed tag is required.');
        }
        $action = collect(app(WebsiteConversionActions::class, ['customer' => $customer])->all())->firstWhere('resource', $actionResource);
        $details = app(GetConversionActionDetails::class, ['customer' => $customer])($customer->cleanGoogleCustomerId(), $actionResource);
        $readback = app(ReconcileCampaignConversionGoals::class, ['customer' => $customer])->inspect($strategy, $resource);
        $status = app(GetCampaignStatus::class, ['customer' => $customer])($customer->cleanGoogleCustomerId(), $resource);
        $sendTo = ($details['conversion_id'] ?? '').'/'.($details['conversion_label'] ?? '');
        if (($action['objective'] ?? null) !== 'purchase' || ($action['always_use_default_value'] ?? null) !== false
            || ($readback['ready'] ?? false) !== true || ($readback['configuration_verified'] ?? false) !== true
            || ($readback['intent']['action_resource'] ?? null) !== $actionResource || ($readback['campaign_resource'] ?? null) !== $resource
            || ! preg_match('/^AW-\d+\/[A-Za-z0-9_-]+$/', $sendTo)
            || ($status['status'] ?? null) !== CampaignStatus::PAUSED) {
            throw new \RuntimeException('The exact paused campaign and website Purchase tag could not be verified.');
        }
        $strategy->refresh()->load('campaign.customer');
        $checkedConfiguration = SetupConversionGoal::configurationFor($strategy);
        if (($checkedConfiguration['request_id'] ?? null) !== $configuration['request_id']
            || ($checkedConfiguration['action_resource'] ?? null) !== $actionResource || ($checkedConfiguration['status'] ?? null) !== 'configured') {
            throw new \RuntimeException('The reviewed purchase goal changed during its Google readback.');
        }
        $configuration = $checkedConfiguration;
        $binding = ['strategy_uuid' => $strategy->uuid, 'request_id' => $configuration['request_id'],
            'action_resource' => $actionResource, 'campaign_resource' => $resource, 'send_to' => $sendTo,
            'origin' => $this->origin($customer->website), 'bound_at' => now()->toIso8601String()];
        $binding = DB::transaction(function () use ($strategy, $configuration, $binding): array {
            $fresh = Strategy::whereKey($strategy->id)->lockForUpdate()->firstOrFail()->load('campaign.customer');
            if (SetupConversionGoal::configurationFor($fresh) !== $configuration || $this->campaignResource($fresh) !== $binding['campaign_resource']
                || $this->origin($fresh->campaign->customer->website) !== $binding['origin']) {
                throw new \RuntimeException('The reviewed goal changed during purchase tracking installation. Retry with current settings.');
            }
            $existing = Setting::get(self::SETTING);
            if (is_array($existing) && is_string($existing['bound_at'] ?? null) && strtotime($existing['bound_at']) !== false
                && strtotime($existing['bound_at']) <= now()->timestamp
                && array_diff_key($existing, ['bound_at' => true]) === array_diff_key($binding, ['bound_at' => true])) {
                $binding['bound_at'] = $existing['bound_at'];
            }
            Setting::set(self::SETTING, json_encode($binding, JSON_THROW_ON_ERROR), 'json');

            return $binding;
        });

        return $binding;
    }

    public function forBrowser(User $user, Request $request): array
    {
        $binding = $this->binding();
        if (! $binding || $this->origin($request->getSchemeAndHttpHost()) !== $binding['origin']) {
            return ['installed' => false, 'pending' => []];
        }
        $pending = $this->receipts($user, $binding)->limit(10)->get()->map(fn (SpectraConversionEvent $event) => [
            'receipt_id' => $event->id, 'transaction_id' => $event->deduplication_key,
            'value_cents' => $this->valueCents($event), 'currency' => $event->currency,
            'send_to' => $binding['send_to'], 'binding_id' => $binding['fingerprint'],
            'ack_url' => route('purchase-tracking.invoked', ['receipt' => $event->id]),
        ])->all();

        return ['installed' => true, 'method' => 'native_verified_checkout', 'pending' => $pending,
            'pending_url' => route('purchase-tracking.pending'), 'watch_for_payment' => $request->query('purchase') === 'success'];
    }

    /** A browser report proves invocation only; Google acceptance/attribution remains unverified. */
    public function acknowledge(User $user, Request $request, int $receiptId, string $fingerprint): void
    {
        $binding = $this->binding();
        abort_unless($binding && hash_equals($binding['fingerprint'], $fingerprint)
            && $this->origin($request->getSchemeAndHttpHost()) === $binding['origin'], 409);
        $event = $this->receipts($user, $binding, pendingOnly: false)->whereKey($receiptId)->firstOrFail();
        SpectraConversionEvent::whereKey($event->id)->whereNull('browser_invoked_at')->update([
            'browser_invoked_at' => now()->format('Y-m-d H:i:sP'), 'browser_target_resource' => $binding['action_resource'],
            'browser_binding_fingerprint' => $fingerprint,
        ]);
    }

    public function statusForStrategy(Strategy $strategy): array
    {
        $binding = $this->binding();
        if (! $binding || $binding['strategy_uuid'] !== $strategy->uuid) {
            return ['connected' => false];
        }

        return ['connected' => true, 'method' => 'native_verified_checkout', 'action_resource' => $binding['action_resource'],
            'request_id' => $binding['request_id'], 'installed_at' => $binding['bound_at'],
            'browser_invocations' => SpectraConversionEvent::where('browser_target_resource', $binding['action_resource'])
                ->where('browser_binding_fingerprint', $binding['fingerprint'])->whereNotNull('browser_invoked_at')->count(),
            'google_conversion_verified' => SetupConversionGoal::conversionVerificationFor($strategy)['verified'],
            'message' => 'Confirmed paid checkout receipts are connected to this Purchase goal with their actual amount, currency and transaction ID. Google delivery and attribution are separate checks.'];
    }

    private function binding(): ?array
    {
        $binding = Setting::get(self::SETTING);
        if (! is_array($binding) || ! is_string($binding['strategy_uuid'] ?? null)
            || ! is_string($binding['send_to'] ?? null) || ! preg_match('/^AW-\d+\/[A-Za-z0-9_-]+$/', $binding['send_to'])
            || ($binding['origin'] ?? null) !== $this->origin(config('app.url')) || ! $this->origin($binding['origin'] ?? null)) {
            return null;
        }
        // This is an operator-installed site-wide tag, intentionally independent
        // of which customer account the purchaser owns.
        $strategy = Strategy::with(['campaign' => fn ($query) => $query->withoutGlobalScope(\App\Models\Scopes\CustomerScope::class)->with('customer')])
            ->where('uuid', $binding['strategy_uuid'])->first();
        if (! $strategy || ! $strategy->campaign?->customer || $this->origin($strategy->campaign->customer->website) !== $binding['origin']) {
            return null;
        }
        $configuration = SetupConversionGoal::configurationFor($strategy);
        if (($configuration['status'] ?? null) !== 'configured' || ($configuration['goal']['objective'] ?? null) !== 'purchase'
            || ($configuration['request_id'] ?? null) !== ($binding['request_id'] ?? null)
            || ($configuration['action_resource'] ?? null) !== ($binding['action_resource'] ?? null)
            || ($configuration['managed_tag_may_exist'] ?? false)
            || $this->campaignResource($strategy) !== ($binding['campaign_resource'] ?? null)) {
            return null;
        }
        try {
            $boundAt = CarbonImmutable::parse($binding['bound_at'] ?? 'invalid');
            if ($boundAt->isFuture()) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }
        $binding['fingerprint'] = hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR));

        return $binding;
    }

    private function receipts(User $user, array $binding, bool $pendingOnly = true)
    {
        return SpectraConversionEvent::where('user_id', $user->id)
            ->where(fn ($query) => $query->where(fn ($query) => $query->where('event', 'paid_setup')->where('mode', 'verified_purchase')->where('deduplication_key', 'like', 'paid_setup:cs_%'))
                ->orWhere(fn ($query) => $query->where('event', 'paid_subscription')->where('mode', 'server_google')->where('deduplication_key', 'like', 'paid_subscription:sub_%')))
            ->where('value', '>', 0)->whereIn('currency', ['USD', 'AUD'])->whereNotNull('occurred_at')
            ->where('occurred_at', '>=', $binding['bound_at'])->where('occurred_at', '>=', now()->subMinutes(30)->toIso8601String())
            ->where('occurred_at', '<=', now()->toIso8601String())->when($pendingOnly, fn ($query) => $query->whereNull('browser_invoked_at'))->orderBy('id');
    }

    private function valueCents(SpectraConversionEvent $event): int
    {
        if (! preg_match('/^(\d+)\.(\d{2})$/', $event->value, $parts)) {
            throw new \UnexpectedValueException('A paid receipt must have an exact two-decimal amount.');
        }

        return (int) $parts[1] * 100 + (int) $parts[2];
    }

    private function campaignResource(Strategy $strategy): ?string
    {
        $resource = $strategy->reusableGoogleCampaignId();

        return $resource && ctype_digit($resource) ? 'customers/'.$strategy->campaign->customer->cleanGoogleCustomerId().'/campaigns/'.$resource : $resource;
    }

    private function origin(?string $url): ?string
    {
        $parts = parse_url($url ?? '');
        if (! $parts || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return 'https://'.strtolower($parts['host']).(isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '');
    }
}
