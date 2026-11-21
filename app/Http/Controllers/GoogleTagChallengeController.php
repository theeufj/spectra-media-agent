<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Services\Conversions\CampaignOutcomeEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** A browser observation alone cannot verify an event, record a conversion or authorize spend. */
class GoogleTagChallengeController extends Controller
{
    public function __invoke(Request $request, Campaign $campaign, string $strategy, CampaignOutcomeEvidence $evidence): Response
    {
        $this->authorize('update', $campaign);
        $data = $request->validate([
            'website_origin' => 'required|url:https|max:2048',
            'challenge' => 'required|uuid', 'send_to' => ['required', 'regex:/^AW-\d+\/[a-zA-Z0-9_-]+$/D'],
            'event_id' => 'required|string|max:160', 'observed_at' => 'required|date',
            'goal' => 'required|in:signup,purchase,lead', 'amount' => 'nullable|required_if:goal,purchase|decimal:0,2|gt:0|max:9999999999',
            'currency' => ['nullable', 'required_if:goal,purchase', 'regex:/^[A-Z]{3}$/D'],
        ]);
        $model = $campaign->strategies()->where('uuid', $strategy)->firstOrFail();
        $binding = $evidence->binding($model);
        $origin = $binding['origin'];
        abort_unless($origin && $data['website_origin'] === $origin && $binding['channel'] === 'google' && $binding['goal'] === $data['goal'], 403);
        $seen = CarbonImmutable::parse($data['observed_at']);
        $eventHash = hash('sha256', $data['event_id']);
        DB::transaction(function () use ($data, $model, $binding, $seen, $eventHash) {
            $query = DB::table('campaign_outcome_challenges')->where('id', $data['challenge'])->where('strategy_id', $model->id)
                ->where('binding', $binding['fingerprint'])->where('send_to', $data['send_to']);
            $challenge = (clone $query)->lockForUpdate()->first();
            abort_unless($challenge && now()->lte(CarbonImmutable::parse($challenge->expires_at))
                && $seen->betweenIncluded(CarbonImmutable::parse($challenge->started_at)->startOfSecond(), now()->addSeconds(5))
                && $seen->gte(now()->subMinutes(2)), 409);
            if ($challenge->browser_event_hash) {
                abort_unless(hash_equals($challenge->browser_event_hash, $eventHash)
                    && CarbonImmutable::parse($challenge->browser_tag_at)->timestamp === $seen->timestamp
                    && (float) $challenge->browser_value === (float) ($data['amount'] ?? 0)
                    && $challenge->browser_currency === ($data['currency'] ?? null), 409);

                return;
            }
            $query->update(['browser_event_hash' => $eventHash, 'browser_tag_at' => $seen->toIso8601String(), 'browser_received_at' => now(),
                'browser_value' => $data['amount'] ?? null, 'browser_currency' => $data['currency'] ?? null]);
        });

        return response()->noContent()->header('Cache-Control', 'no-store');
    }
}
