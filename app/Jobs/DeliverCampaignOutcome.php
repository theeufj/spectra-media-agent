<?php

namespace App\Jobs;

use App\Models\Strategy;
use App\Services\Conversions\CampaignOutcomeEvidence;
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
        if (! is_object($row) || ! in_array($row->delivery_status, ['pending', 'failed', 'accepted'], true) || ! $row->payload) {
            return;
        }
        $strategy = Strategy::findOrFail($row->strategy_id);
        $binding = $evidence->binding($strategy);
        if ($binding['fingerprint'] !== $row->binding || Carbon::parse($row->occurred_at)->lt(now()->subDays(7))) {
            $this->save('stale');

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
            if (empty($payload['fbc']) || ($test && empty($payload['test_code']))) {
                $this->save('awaiting_evidence');

                return;
            }
            $result = app(ConversionsApiService::class, ['customer' => $customer])->sendEvents($pixel, [[
                'event_name' => match ($row->goal) {
                    'signup' => 'CompleteRegistration', 'purchase' => 'Purchase', default => 'Lead'
                },
                'event_time' => Carbon::parse($row->occurred_at)->timestamp, 'event_source_url' => $payload['source_url'],
                'event_id' => $payload['source_id'], 'action_source' => 'website', 'user_data' => ['fbc' => $payload['fbc']],
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
                // The published website tag already delivers this event.
                $this->save('browser_delivery');

                return;
            }
            $challenge = DB::table('campaign_outcome_challenges')->where('id', $payload['challenge'])
                ->where('strategy_id', $strategy->id)->where('source_key', $row->source_key)->where('binding', $row->binding)->first();
            $seen = isset($payload['tag_seen_at']) ? Carbon::parse($payload['tag_seen_at'])->utc() : null;
            if (! $challenge || ! $seen || ! filled($action['send_to'] ?? null) || $challenge->send_to !== $action['send_to']
                || ($payload['tag_send_to'] ?? null) !== $action['send_to'] || ($payload['tag_event_id'] ?? null) !== $payload['source_id']
                || $seen->lt(Carbon::parse($challenge->started_at)->startOfSecond()) || $seen->gt(Carbon::parse($challenge->expires_at))
                || abs($seen->timestamp - Carbon::parse($row->occurred_at)->timestamp) > 30) {
                $this->save('awaiting_evidence');

                return;
            }
            $tagAt = $action['last_tag_at'] ?? null;
            $zone = $action['time_zone'] ?? null;
            if ($tagAt && $zone && $tagAt !== $challenge->previous_tag_at && Carbon::parse($tagAt, $zone)->timestamp === $seen->timestamp) {
                $this->save('verified', 'google-tag:'.$action['send_to'].':'.$seen->timestamp);
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
        if ($row->provider_receipt) {
            $status = $google->retrieveRequestStatus($row->provider_receipt);
            $diagnostics = CheckGoogleConversionProcessing::diagnostics($status['destinations'] ?? [], $binding['destination']);
            if ($diagnostics['status'] === 'SUCCESS' && empty($diagnostics['warnings'])) {
                $this->save('processed', $row->provider_receipt);
            } elseif (in_array($diagnostics['status'], ['FAILED', 'PARTIAL_SUCCESS'], true)) {
                $this->save('rejected', $row->provider_receipt);
            } else {
                $this->release(1800);
            }

            return;
        }
        $ids = array_filter(array_intersect_key($payload, array_flip(['gclid', 'gbraid', 'wbraid'])));
        if (count($ids) !== 1) {
            $this->save('awaiting_evidence');

            return;
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
        $this->save('accepted', $result['requestId'], keepPayload: true);
        $this->release(1800);
    }

    public function failed(\Throwable $failure): void
    {
        DB::table('campaign_outcome_events')->where('id', $this->outcomeId)->whereIn('delivery_status', ['pending', 'accepted'])
            ->update(['delivery_status' => 'failed', 'updated_at' => now()]);
    }

    private function save(string $status, ?string $receipt = null, bool $keepPayload = false): void
    {
        DB::table('campaign_outcome_events')->where('id', $this->outcomeId)->update([
            'delivery_status' => $status, 'provider_receipt' => $receipt, 'updated_at' => now(),
            ...($keepPayload ? [] : ['payload' => null]),
        ]);
    }
}
