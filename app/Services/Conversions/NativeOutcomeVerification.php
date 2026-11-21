<?php

namespace App\Services\Conversions;

use App\Jobs\CheckGoogleConversionProcessing;
use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Models\Strategy;
use App\Services\FacebookAds\PixelService;
use App\Services\GoogleAds\DataManagerService;
use App\Services\OwnSitePurchaseTracking;
use Illuminate\Support\Facades\DB;

/** Replay a genuine owned SaaS receipt read-only, without generating a second advertising event. */
class NativeOutcomeVerification
{
    public function owns(Strategy $strategy, array $binding): bool
    {
        $uuid = config('conversions.advertiser_customer_uuid');
        $customer = $strategy->campaign->customer;
        if (! $uuid) {
            $saved = Setting::get(OwnSitePurchaseTracking::SETTING);
            $priorStrategy = is_array($saved) && ! empty($saved['strategy_uuid'])
                ? Strategy::where('uuid', $saved['strategy_uuid'])->first() : null;
            $uuid = $priorStrategy?->campaign?->customer?->uuid;
        }
        if (! $uuid || $uuid !== $customer->uuid || $binding['origin'] !== 'https://sitetospend.com') {
            return false;
        }

        return $binding['channel'] === 'google' ? $binding['account'] === (string) config('conversions.google_ads_customer_id')
            : $binding['channel'] === 'facebook' && $binding['destination'] === (string) config('services.facebook.spectra_pixel_id');
    }

    public function replay(Strategy $strategy, array $binding): bool
    {
        if (! $this->owns($strategy, $binding) || ! in_array($binding['goal'], ['signup', 'purchase'], true)) {
            return false;
        }
        $events = $binding['goal'] === 'signup' ? ['signup'] : ['paid_setup', 'paid_subscription'];
        $query = SpectraConversionEvent::whereIn('event', $events)->where('verified_source->consent', true)->whereHas('user', fn ($q) => $q->where('tenant_key', 'sitetospend')->where('ad_measurement_consent', true))
            ->whereNotNull('deduplication_key')->whereBetween('occurred_at', [now()->subDays(30)->toIso8601String(), now()->toIso8601String()])->orderByDesc('occurred_at');
        if ($binding['goal'] === 'purchase') {
            $query->where('value', '>', 0);
        }
        if ($binding['channel'] === 'google') {
            $receipt = $query->where('google_conversion_resource', $binding['destination'])->where('uploaded_to_google', true)
                ->whereNotNull('google_request_id')->where('google_processing_status', 'SUCCESS')->first();
            if (! $receipt || ! $this->isBusinessReceipt($receipt) || $receipt->googleDeliveryStatus() !== 'processed') {
                return false;
            }
            $id = $receipt->google_request_id;
            $diagnostic = app(DataManagerService::class)->retrieveRequestStatus($id);
            $proof = CheckGoogleConversionProcessing::diagnostics($diagnostic['destinations'] ?? [], $binding['destination']);
            if (! $diagnostic['success'] || $proof['status'] !== 'SUCCESS' || ! empty($proof['warnings'])) {
                return false;
            }
        } else {
            $name = $binding['goal'] === 'signup' ? 'CompleteRegistration' : 'Purchase';
            $receipt = $query->where('meta_delivery->dataset', $binding['destination'])->where('meta_delivery->event', $name)
                ->where('meta_delivery->status', 'accepted')->whereNotNull('meta_delivery->trace')->first();
            if (! $receipt || ! $this->isBusinessReceipt($receipt) || ($receipt->meta_delivery['events_received'] ?? null) !== 1
                || ($receipt->meta_delivery['event_id'] ?? null) !== hash('sha256', $receipt->deduplication_key)
                || ! app(PixelService::class, ['customer' => $strategy->campaign->customer])->isLinked($binding['destination'], $binding['account'])) {
                return false;
            }
            $id = $receipt->meta_delivery['trace'];
        }
        if (! is_string($id) || trim($id) === '') {
            return false;
        }
        $key = hash('sha256', $binding['fingerprint'].':'.$receipt->deduplication_key);
        DB::table('campaign_outcome_events')->upsert([['strategy_id' => $strategy->id, 'binding' => $binding['fingerprint'],
            'source_key' => $key, 'goal' => $binding['goal'], 'mode' => 'test', 'source' => 'native_receipt_replay',
            'ad_user_data' => 'granted', 'ad_personalization' => null, 'consent_observed_at' => $receipt->created_at?->toIso8601String(),
            ...OutcomeReceiptAudit::providerIdentity($binding['channel'] === 'facebook' ? $receipt->meta_delivery['event_id'] : $receipt->deduplication_key),
            'occurred_at' => $receipt->occurred_at->toIso8601String(), 'delivery_status' => 'verified', 'provider_receipt' => $id,
            'created_at' => now(), 'updated_at' => now()]], ['strategy_id', 'mode', 'source_key'], ['updated_at', 'delivery_status', 'provider_receipt']);

        return true;
    }

    private function isBusinessReceipt(SpectraConversionEvent $event): bool
    {
        $source = $event->verified_source;
        if (! $event->occurred_at || ($source['user_id'] ?? null) !== $event->user_id || ($source['consent'] ?? null) !== true) {
            return false;
        }
        if ($event->event === 'signup') {
            return ($source['kind'] ?? null) === 'registration' && $event->mode === 'server_google'
                && $event->user?->created_at?->timestamp === $event->occurred_at->timestamp
                && $event->deduplication_key === "signup:{$event->user_id}:{$event->occurred_at->timestamp}";
        }
        $subscription = $event->event === 'paid_subscription';
        $transaction = $source['transaction'] ?? '';

        return ($source['livemode'] ?? null) === true && is_string($transaction)
            && ($source['kind'] ?? null) === ($subscription ? 'stripe_invoice' : 'stripe_checkout')
            && preg_match($subscription ? '/^in_[a-zA-Z0-9]+$/D' : '/^cs_live_[a-zA-Z0-9]+$/D', $source['id'] ?? '') === 1
            && ($subscription ? preg_match('/^sub_[a-zA-Z0-9]+$/D', $transaction) === 1 : $source['id'] === $transaction)
            && $event->deduplication_key === $event->event.':'.$transaction && in_array($event->currency, ['AUD', 'USD'], true)
            && $event->currency === ($source['currency'] ?? null) && ($source['paid_at'] ?? null) === $event->occurred_at->timestamp
            && is_int($source['paid_cents'] ?? null) && $source['paid_cents'] > 0
            && $source['paid_cents'] === (int) str_replace('.', '', $event->value);
    }
}
