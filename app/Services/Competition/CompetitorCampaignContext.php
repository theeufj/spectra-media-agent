<?php

namespace App\Services\Competition;

use App\Models\Campaign;
use App\Models\Competitor;
use App\Models\Recommendation;

/** Evidence is tenant-scoped, dated, bounded, and kept with every resulting action. */
class CompetitorCampaignContext
{
    public function forCampaign(Campaign $campaign): array
    {
        $customer = $campaign->customer;
        if (! $campaign->exists || ! $customer?->exists) {
            return [];
        }

        $sources = [];
        foreach (['competitive_strategy' => 'competitive_strategy_updated_at', 'war_room_gap_analysis' => 'war_room_gap_analysis_at'] as $field => $date) {
            if ($customer->$field && $customer->$date?->greaterThanOrEqualTo(now()->subDays(30))) {
                $sources[] = $this->source($field, $customer->$date->toIso8601String(), $customer->$field);
            }
        }

        // Recent analysed competitors, not the entire discovery directory. Keywords
        // found on websites are research hypotheses, never proof of paid targeting.
        foreach (Competitor::where('customer_id', $customer->id)->where('last_analyzed_at', '>=', now()->subDays(30))
            ->orderByDesc('last_analyzed_at')->limit(10)->get() as $competitor) {
            $sources[] = $this->source('competitor:'.$competitor->id, $competitor->last_analyzed_at->toIso8601String(), [
                'domain' => $competitor->domain,
                'url' => $competitor->url,
                'messaging' => $competitor->messaging_analysis,
                'website_keyword_themes' => $competitor->keywords_detected,
                'pricing' => $competitor->pricing_info,
            ]);
        }
        foreach (Recommendation::where('campaign_id', $campaign->id)->where('source', 'competitor_signal')
            ->where('created_at', '>=', now()->subDays(14))->latest()->limit(5)->get() as $signal) {
            $sources[] = $this->source('auction_signal:'.$signal->id, $signal->created_at->toIso8601String(), $signal->evidence ?? []);
        }

        return $sources;
    }

    private function source(string $kind, string $observedAt, array $data): array
    {
        return [
            'id' => $kind.':'.substr(hash('sha256', json_encode([$observedAt, $data])), 0, 16),
            'kind' => $kind, 'observed_at' => $observedAt, 'data' => $data,
        ];
    }

    public function attribute(array $recommendations, array $sources, array $metrics): array
    {
        $indexed = array_column($sources, null, 'id');
        foreach ($recommendations['recommendations'] ?? [] as $index => $rec) {
            // The model cannot invent evidence or select another tenant's record.
            $ids = array_values(array_filter((array) ($rec['evidence_ids'] ?? []), fn ($id) => is_string($id) && isset($indexed[$id])));
            unset($rec['source'], $rec['evidence'], $rec['baseline']);
            if ($ids !== []) {
                $rec['source'] = 'competitor';
                $rec['evidence'] = array_values(array_intersect_key($indexed, array_flip($ids)));
                $rec['baseline'] = $metrics;
            } elseif (! empty($rec['evidence_ids']) || str_starts_with((string) ($rec['type'] ?? ''), 'COMPETITOR_')) {
                // A test with no verifiable provenance must never become executable.
                unset($recommendations['recommendations'][$index]);

                continue;
            }
            $recommendations['recommendations'][$index] = $rec;
        }
        $recommendations['recommendations'] = array_values(array_filter($recommendations['recommendations'] ?? [],
            fn ($rec) => ! str_starts_with((string) ($rec['type'] ?? ''), 'COMPETITOR_') || ($rec['source'] ?? null) === 'competitor'));

        return $recommendations;
    }

    public function recordSignal(Campaign $campaign, array $signal): void
    {
        Recommendation::firstOrCreate([
            'fingerprint' => hash('sha256', 'signal:'.$campaign->id.':'.now()->startOfWeek()->toDateString().':'.json_encode($signal)),
        ], [
            'campaign_id' => $campaign->id, 'type' => 'BIDDING', 'source' => 'competitor_signal',
            'status' => 'observed', 'requires_approval' => false,
            'rationale' => $signal['recommendation'], 'evidence' => $signal,
        ]);
    }
}
