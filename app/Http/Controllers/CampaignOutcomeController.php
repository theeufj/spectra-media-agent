<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverCampaignOutcome;
use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\Conversions\AdvertiserOutcomeOrigin;
use App\Services\Conversions\CampaignOutcomeEvidence;
use App\Services\Conversions\MerchantOrders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CampaignOutcomeController extends Controller
{
    public function show(Campaign $campaign, string $strategy, CampaignOutcomeEvidence $evidence): \Inertia\Response
    {
        $this->authorize('update', $campaign);
        $record = $campaign->strategies()->where('uuid', $strategy)->firstOrFail();
        $route = ['campaign' => $campaign, 'strategy' => $record->uuid];

        return \Inertia\Inertia::render('Campaigns/OutcomeEvidence', ['campaign' => $campaign->only('uuid'), 'strategy' => $record->only('uuid', 'platform'),
            'evidence' => $evidence->forStrategy($record), 'setupUrl' => route('campaigns.outcome.setup', $route), 'testUrl' => route('campaigns.outcome.test', $route)]);
    }

    /** Authenticated server-to-server handoff. Browser snippets must never contain this key. */
    public function setup(Campaign $campaign, string $strategy, CampaignOutcomeEvidence $evidence): JsonResponse
    {
        $this->authorize('update', $campaign);
        $record = DB::transaction(function () use ($campaign, $strategy) {
            $record = $campaign->strategies()->where('uuid', $strategy)->lockForUpdate()->firstOrFail();
            if (! $record->conversion_webhook_secret) {
                $record->forceFill(['conversion_webhook_secret' => Str::random(64)])->save();
            }

            return $record;
        });

        $origin = CampaignOutcomeEvidence::origin($campaign->customer->website);

        return response()->json(['website_file' => $origin ? app(AdvertiserOutcomeOrigin::class)->file($campaign->customer, $origin) : null, 'endpoint' => route('outcomes.record', $record->uuid), 'signing_secret' => $record->conversion_webhook_secret,
            'binding' => $evidence->binding($record)])->header('Cache-Control', 'private, no-store');
    }

    public function begin(Campaign $campaign, string $strategy, CampaignOutcomeEvidence $evidence): JsonResponse
    {
        $this->authorize('update', $campaign);
        $record = $campaign->strategies()->where('uuid', $strategy)->firstOrFail();
        $binding = $evidence->binding($record);
        abort_unless($binding['origin'] && $binding['destination'] && $binding['goal'], 422, 'Select a website, goal and destination first.');
        $details = null;
        if ($binding['channel'] === 'google') {
            $details = app(\App\Services\GoogleAds\ConversionTrackingService::class, ['customer' => $campaign->customer])
                ->outcomeDestination($binding['account'], $binding['destination']);
            abort_unless($details && $details['category'] === ['signup' => 'SIGNUP', 'purchase' => 'PURCHASE', 'lead' => 'SUBMIT_LEAD_FORM'][$binding['goal']], 422,
                'The selected Google action is unavailable or has a different goal.');
        }
        if (app(\App\Services\Conversions\NativeOutcomeVerification::class)->replay($record, $binding)) {
            return response()->json(['verified' => true, 'method' => 'native_receipt_replay',
                'goal' => $binding['goal'], 'destination' => $binding['destination']])->header('Cache-Control', 'private, no-store');
        }
        abort_unless(app(AdvertiserOutcomeOrigin::class)->verify($campaign->customer, $binding['origin']), 422, 'Publish your workspace verification file at the exact website URL shown in backend setup, then retry.');
        $nonce = (string) Str::uuid();
        DB::table('campaign_outcome_challenges')->insert(['id' => $nonce, 'strategy_id' => $record->id, 'binding' => $binding['fingerprint'],
            'previous_tag_at' => $details['last_tag_at'] ?? null, 'send_to' => $details['send_to'] ?? null,
            'started_at' => now()->toIso8601String(), 'expires_at' => now()->addMinutes(30)->toIso8601String()]);

        return response()->json(['challenge' => $nonce, 'expires_at' => now()->addMinutes(30)->toIso8601String(),
            'send_to' => $details['send_to'] ?? null, 'goal' => $binding['goal'], 'destination' => $binding['destination']])->header('Cache-Control', 'private, no-store');
    }

    public function record(Request $request, string $strategy, CampaignOutcomeEvidence $evidence): JsonResponse
    {
        $record = Strategy::where('uuid', $strategy)->firstOrFail();
        $stamp = $request->header('X-Outcome-Timestamp', '');
        abort_unless($record->conversion_webhook_secret && ctype_digit($stamp) && abs(now()->timestamp - (int) $stamp) <= 300
            && hash_equals(hash_hmac('sha256', $stamp.'.'.$request->getContent(), $record->conversion_webhook_secret), $request->header('X-Outcome-Signature', '')), 403);
        $input = $request->validate([
            'source_id' => 'required|string|max:160', 'goal' => 'required|in:signup,purchase,lead,refund',
            'order_id' => 'nullable|required_if:goal,refund|string|max:160',
            'mode' => 'required|in:test,live', 'occurred_at' => 'required|date|before_or_equal:now|after:-7 days',
            'source_url' => 'required|url:https|max:2048', 'amount' => 'nullable|required_if:goal,purchase,refund|decimal:0,2|gt:0|max:9999999999',
            'currency' => ['nullable', 'required_if:goal,purchase,refund', 'regex:/^[A-Z]{3}$/D'],
            'ad_user_data' => 'nullable|required_unless:goal,refund|in:granted,denied,unknown', 'ad_personalization' => 'nullable|in:granted,denied,unknown',
            'gclid' => 'nullable|string|max:255', 'gbraid' => 'nullable|string|max:255', 'wbraid' => 'nullable|string|max:255',
            'fbc' => ['nullable', 'regex:/^fb\.\d+\.\d{13}\.[a-zA-Z0-9._-]+$/D', 'max:320'],
            'challenge' => 'required_if:mode,test|nullable|uuid',
            'tag_send_to' => 'nullable|string|max:160', 'tag_event_id' => 'nullable|string|max:160', 'tag_seen_at' => 'nullable|date|before_or_equal:now',
            'test_code' => 'nullable|string|max:80|alpha_num:ascii',
        ]);
        $binding = $evidence->binding($record);
        $refund = $input['goal'] === 'refund';
        if ((! $refund && (! $binding['destination'] || $input['goal'] !== $binding['goal'])) || ($refund && $input['mode'] !== 'live')
            || CampaignOutcomeEvidence::origin($input['source_url']) !== $binding['origin']
            || parse_url($input['source_url'], PHP_URL_USER) !== null || parse_url($input['source_url'], PHP_URL_PASS) !== null) {
            throw ValidationException::withMessages(['goal' => 'The event must match the selected website, goal, account and destination.']);
        }
        $occurred = Carbon::parse($input['occurred_at'])->utc();
        if ($refund) {
            $id = app(MerchantOrders::class)->refund($record->campaign->customer_id, $input, $occurred);

            return response()->json(['refund_id' => $id, 'status' => 'recorded'], 202);
        }
        $businessId = $input['goal'] === 'purchase' ? ($input['order_id'] ?? $input['source_id']) : $input['source_id'];
        abort_unless($binding['origin'] && app(AdvertiserOutcomeOrigin::class)->verify($record->campaign->customer, $binding['origin']), 422, 'Verify this website’s ownership before sending business events.');
        $key = hash('sha256', $businessId);
        $event = DB::transaction(function () use ($record, $input, $binding, $key, $businessId, $occurred) {
            $strategy = Strategy::whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(CampaignOutcomeEvidence::class)->binding($strategy)['fingerprint'] === $binding['fingerprint'], 409);
            $store = DB::table('campaign_outcome_events');
            $prior = (clone $store)->where('strategy_id', $strategy->id)->where('mode', $input['mode'])->where('source_key', $key)->first();
            if ($input['mode'] === 'live' && $input['goal'] === 'purchase') {
                app(MerchantOrders::class)->purchase($strategy->campaign->customer_id, $businessId, $input['currency'], (string) $input['amount'], $occurred);
            }
            if ($prior) {
                abort_unless($prior->binding === $binding['fingerprint'] && $prior->goal === $input['goal']
                    && Carbon::parse($prior->occurred_at)->equalTo($occurred) && (float) $prior->amount === (float) ($input['amount'] ?? 0)
                    && $prior->currency === ($input['currency'] ?? null), 409);

                return $prior;
            }
            if ($input['mode'] === 'test') {
                $challenge = DB::table('campaign_outcome_challenges')->where('id', $input['challenge'])->where('strategy_id', $strategy->id)
                    ->where('binding', $binding['fingerprint'])->whereNull('source_key')->lockForUpdate()->first();
                abort_unless($challenge && now()->lte(Carbon::parse($challenge->expires_at))
                    && $occurred->gte(Carbon::parse($challenge->started_at)->startOfSecond()), 409, 'Start a fresh website test.');
                DB::table('campaign_outcome_challenges')->where('id', $challenge->id)->update(['source_key' => $key]);
            }
            $id = $store->insertGetId(['strategy_id' => $strategy->id, 'binding' => $binding['fingerprint'], 'source_key' => $key,
                'goal' => $input['goal'], 'source' => 'signed_website', 'mode' => $input['mode'], 'occurred_at' => $occurred->toIso8601String(),
                'amount' => $input['amount'] ?? null, 'currency' => $input['currency'] ?? null,
                'payload' => Crypt::encryptString(json_encode($input, JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now()]);

            return $store->where('id', $id)->first();
        });
        if (in_array($event->delivery_status, ['pending', 'failed'], true)) {
            DeliverCampaignOutcome::dispatch($event->id)->afterCommit();
        }

        return response()->json(['event_id' => $event->id, 'delivery_status' => $event->delivery_status], 202);
    }
}
