<?php

namespace App\Jobs;

use App\Models\Strategy;
use App\Services\Conversions\CampaignOutcomeEvidence;
use App\Services\Conversions\OutcomeReceiptAudit;
use App\Services\FacebookAds\ConversionsApiService;
use App\Services\FacebookAds\PixelService;
use App\Services\GoogleAds\ConversionTrackingService;
use App\Services\GoogleAds\DataManagerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class DeliverCampaignOutcome implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 48;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public int $outcomeId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('advertiser-conversion:'.$this->outcomeId))->releaseAfter(60)->expireAfter(180)];
    }

    public function handle(CampaignOutcomeEvidence $evidence, DataManagerService $google): void
    {
        $row = DB::table('campaign_outcome_events')->find($this->outcomeId);
        if (! is_object($row) || ! in_array($row->delivery_status, ['pending', 'failed', 'accepted'], true)) {
            return;
        }
        $strategy = Strategy::findOrFail($row->strategy_id);
        $binding = $evidence->binding($strategy);
        if ($binding['fingerprint'] !== $row->binding || Carbon::parse($row->occurred_at)->lt(now()->subDays(7))) {
            $this->save('stale');

            return;
        }
        if ($row->provider_receipt && $binding['channel'] === 'google' && $row->mode === 'live') {
            $status = $google->retrieveRequestStatus($row->provider_receipt);
            if (! $status['success']) {
                throw new \RuntimeException('Google processing status is temporarily unavailable.');
            }
            $diagnostics = CheckGoogleConversionProcessing::diagnostics($status['destinations'] ?? [], $binding['destination']);
            if ($diagnostics['status'] === 'SUCCESS' && empty($diagnostics['warnings'])) {
                $this->save('processed');
            } elseif (in_array($diagnostics['status'], ['FAILED', 'PARTIAL_SUCCESS'], true)) {
                $this->save('rejected');
            } else {
                $this->release(1800);
            }

            return;
        }
        if (! $row->payload) {
            return;
        }
        $payload = json_decode(Crypt::decryptString($row->payload), true, 512, JSON_THROW_ON_ERROR);
        if ($payload['ad_user_data'] !== 'granted') {
            $this->save('consent_unavailable');

            return;
        }
        $customer = $strategy->campaign->customer;
        if ($binding['channel'] === 'facebook') {
            $pixel = $binding['destination'];
            if (! app(PixelService::class, ['customer' => $customer])->isLinked($pixel, $binding['account'])) {
                throw new \RuntimeException('The advertiser dataset is not linked to the selected Meta account.');
            }
            $test = $row->mode === 'test';
            $matching = array_filter([
                'fbc' => $payload['fbc'] ?? null,
                'em_sha256' => $payload['meta_email_sha256'] ?? null,
                'external_id_sha256' => $payload['meta_external_id_sha256'] ?? null,
            ]);
            if (! $matching || empty($payload['client_user_agent']) || ($test && empty($payload['test_code']))) {
                $this->save('awaiting_evidence');

                return;
            }
            $result = app(ConversionsApiService::class, ['customer' => $customer])->sendEvents($pixel, [[
                'event_name' => match ($row->goal) {
                    'signup' => 'CompleteRegistration', 'purchase' => 'Purchase', default => 'Lead'
                },
                'event_time' => Carbon::parse($row->occurred_at)->timestamp, 'event_source_url' => $payload['source_url'],
                'event_id' => $payload['source_id'], 'action_source' => 'website', 'user_data' => $matching + ['client_user_agent' => $payload['client_user_agent']],
                ...($test ? ['opt_out' => true] : []),
                'custom_data' => $row->amount ? ['value' => (float) $row->amount, 'currency' => $row->currency] : [],
            ]], $test ? $payload['test_code'] : null);
            if (($result['events_received'] ?? null) !== 1 || ! is_string($result['fbtrace_id'] ?? null) || $result['fbtrace_id'] === '') {
                throw new \RuntimeException('Meta did not acknowledge the exact event.');
            }
            $this->save($test ? 'verified' : 'accepted', $result['fbtrace_id']);

            return;
        }
        $action = app(ConversionTrackingService::class, ['customer' => $customer])->outcomeDestination($binding['account'], $binding['destination']);
        $category = ['signup' => 'SIGNUP', 'purchase' => 'PURCHASE', 'lead' => 'SUBMIT_LEAD_FORM'][$row->goal];
        if (($action['category'] ?? null) === $category && ($action['type'] ?? null) === 'WEBPAGE') {
            if ($row->mode !== 'test') {
                if (($payload['tag_event_id'] ?? null) === $payload['source_id'] && ! $row->provider_event_id) {
                    $this->recordProviderIdentity($payload['source_id']);
                }
                // The published website tag already delivers this event.
                $this->save('browser_delivery');

                return;
            }
            $challenge = DB::table('campaign_outcome_challenges')->where('id', $payload['challenge'])
                ->where('strategy_id', $strategy->id)->where('source_key', $row->source_key)->where('binding', $row->binding)->first();
            if (! $challenge || ! filled($action['send_to'] ?? null) || $challenge->send_to !== $action['send_to']) {
                $this->save('awaiting_evidence');

                return;
            }
            if (! $challenge->browser_tag_at && now()->lte(Carbon::parse($challenge->expires_at))) {
                // The signed backend webhook and the consenting browser can arrive in either order.
                $this->release(60);

                return;
            }
            $eventId = $row->goal === 'purchase' ? ($payload['order_id'] ?? $payload['source_id']) : $payload['source_id'];
            $seen = $challenge->browser_tag_at ? Carbon::parse($challenge->browser_tag_at)->utc() : null;
            if (! $seen || ! $challenge->browser_event_hash || ! hash_equals($challenge->browser_event_hash, hash('sha256', $eventId))
                || $seen->lt(Carbon::parse($challenge->started_at)->startOfSecond()) || $seen->gt(Carbon::parse($challenge->expires_at))
                || abs($seen->timestamp - Carbon::parse($row->occurred_at)->timestamp) > 90
                || ($row->goal === 'purchase' && ($challenge->browser_currency !== $row->currency || $challenge->browser_value !== $row->amount))) {
                $this->save('awaiting_evidence');

                return;
            }
            $tagAt = $action['last_tag_at'] ?? null;
            $zone = $action['time_zone'] ?? null;
            $received = $tagAt && $zone ? Carbon::parse($tagAt, $zone)->utc() : null;
            // Google exposes the latest tag activity, not an event-ID receipt. Allow five seconds of
            // browser clock skew and 30 seconds of network latency after the observed transaction hit.
            $previous = $zone && $challenge->previous_tag_at ? Carbon::parse($challenge->previous_tag_at, $zone)->utc() : null;
            if ($received && (! $previous || $received->gt($previous)) && $received->lte(now()->addSeconds(5)) && $received->betweenIncluded($seen->copy()->subSeconds(5), $seen->copy()->addSeconds(30))
                && $received->gte(Carbon::parse($challenge->started_at)->startOfSecond())) {
                $this->recordProviderIdentity($eventId);
                $this->save('verified', 'google-tag-activity:'.$action['send_to'].':'.$received->timestamp);
            } elseif (now()->diffInHours(Carbon::parse($challenge->started_at), true) < 24) {
                $this->release(900);
            } else {
                $this->save('awaiting_evidence');
            }

            return;
        }
        if (($action['category'] ?? null) !== $category || ($action['type'] ?? null) !== 'UPLOAD_CLICKS') {
            $this->save('awaiting_evidence');

            return;
        }
        $ids = array_filter(array_intersect_key($payload, array_flip(['gclid', 'gbraid', 'wbraid'])));
        if (count($ids) !== 1) {
            $this->save('awaiting_evidence');

            return;
        }
        if (! $row->provider_event_id) {
            $this->recordProviderIdentity($row->source_key);
        }
        $test = $row->mode === 'test';
        $consent = ['adUserData' => 'CONSENT_GRANTED'];
        if (in_array($payload['ad_personalization'] ?? '', ['granted', 'denied'], true)) {
            $consent['adPersonalization'] = $payload['ad_personalization'] === 'granted' ? 'CONSENT_GRANTED' : 'CONSENT_DENIED';
        }
        $result = $google->ingestConversion($binding['account'], basename($binding['destination']), $ids,
            (float) $row->amount, $row->currency ?? $customer->billingCurrency(), Carbon::parse($row->occurred_at),
            validateOnly: $test, transactionId: $row->source_key, consent: $consent);
        if (! $result['success']) {
            throw new \RuntimeException('Google could not accept the signed website event.');
        }
        if ($test) {
            // This verifies serialization only. A real matching processed receipt is required for end-to-end proof.
            $this->save('validated_only');

            return;
        }
        if (! filled($result['requestId'] ?? null)) {
            throw new \RuntimeException('Google returned no processing receipt.');
        }
        $this->save('accepted', $result['requestId']);
        $this->release(1800);
    }

    public function failed(\Throwable $failure): void
    {
        $store = DB::table('campaign_outcome_events')->where('id', $this->outcomeId);
        $row = (clone $store)->first();
        if (! $row || ! in_array($row->delivery_status, ['pending', 'failed', 'accepted'], true)) {
            return;
        }
        if ($row->delivery_status === 'accepted') {
            $strategy = Strategy::find($row->strategy_id);
            if (! $strategy || app(CampaignOutcomeEvidence::class)->binding($strategy)['channel'] !== 'google') {
                return;
            }
        }
        $store->where('delivery_status', $row->delivery_status)->update([
            'delivery_status' => 'failed', 'updated_at' => now(),
            ...($row->provider_receipt || $row->retry_payload_hash ? ['payload' => null] : []),
        ]);
    }

    private function recordProviderIdentity(string $eventId): void
    {
        DB::table('campaign_outcome_events')->where('id', $this->outcomeId)->whereNull('provider_event_id')
            ->update(OutcomeReceiptAudit::providerIdentity($eventId));
    }

    private function save(string $status, ?string $receipt = null): void
    {
        DB::table('campaign_outcome_events')->where('id', $this->outcomeId)->update([
            'delivery_status' => $status, 'updated_at' => now(), 'payload' => null,
            ...($receipt !== null ? ['provider_receipt' => $receipt] : []),
        ]);
    }
}
