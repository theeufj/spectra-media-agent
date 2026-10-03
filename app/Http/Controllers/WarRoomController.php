<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeWarRoomCompetitor;
use App\Models\ABTest;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Competitor;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;
use App\Models\LinkedInAdsPerformanceData;
use App\Models\MicrosoftAdsPerformanceData;
use App\Models\Notification;
use App\Models\Recommendation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class WarRoomController extends Controller
{
    public function index(Request $request)
    {
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return redirect()->route('customers.create');
        }

        $canAccess = $request->user()->hasFeature('war_room');

        if (! $canAccess) {
            return Inertia::render('Strategy/WarRoom', [
                'canAccessWarRoom' => false,
                'health' => null,
                'activities' => [],
                'recommendations' => [],
                'advisories' => [],
                'performance' => null,
                'alerts' => [],
                'abTests' => [],
            ]);
        }

        $campaignIds = Campaign::where('customer_id', $customer->id)->pluck('id');

        // 1. Health status from Redis cache
        $health = Cache::get("health_check:customer:{$customer->id}:latest", [
            'overall_health' => 'unknown',
            'issues' => [],
            'warnings' => [],
            'recommendations' => [],
        ]);

        // 2. Recent agent activity
        $activities = AgentActivity::where('customer_id', $customer->id)
            ->latest()
            ->take(20)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'agent_type' => $a->agent_type,
                'action' => $a->action,
                'description' => $a->description,
                'status' => $a->status,
                'details' => $a->details,
                'created_at' => $a->created_at->toIso8601String(),
            ]);

        // 3. Pending optimization recommendations
        $pendingRecommendations = Recommendation::whereIn('campaign_id', $campaignIds)
            ->where('status', 'pending')
            ->with('campaign:id,uuid,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        // Only competitor recommendations have a job that applies and verifies
        // an approved change. Ordinary optimizer cards were being marked
        // "approved" with no platform operation behind that button.
        $recommendations = (clone $pendingRecommendations)
            ->where('source', 'competitor')
            ->where('requires_approval', true)
            ->take(100)
            ->get()
            ->unique(fn (Recommendation $r) => $r->campaign_id.'|'.$r->type.'|'.json_encode($r->target_entity))
            ->take(20)
            ->values()
            ->map(fn ($r) => [
                'id' => $r->id,
                'campaign_id' => $r->campaign_id,
                'campaign_name' => $r->campaign?->name,
                'campaign_uuid' => $r->campaign?->uuid,
                'type' => $r->type,
                'rationale' => $r->rationale,
                'parameters' => $r->parameters,
                'requires_approval' => $r->requires_approval,
                'created_at' => $r->created_at->toIso8601String(),
            ]);

        $advisories = (clone $pendingRecommendations)
            ->where(fn ($query) => $query->whereNull('source')->orWhere('source', '!=', 'competitor')->orWhere('requires_approval', false))
            ->take(100)
            ->get()
            ->unique(fn (Recommendation $r) => $r->campaign_id.'|'.$r->type)
            ->take(5)
            ->values()
            ->map(fn (Recommendation $r) => [
                'id' => $r->id,
                'campaign_name' => $r->campaign?->name,
                'type' => $r->type,
                'rationale' => $r->rationale,
            ]);

        // 4. Cross-platform performance (last 7 days)
        $since = now()->subDays(7);
        $performance = $this->aggregatePerformance($campaignIds, $since);

        // 5. Unread alerts / notifications
        $alerts = Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->latest()
            ->take(10)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'message' => $n->message,
                'action_url' => $n->action_url,
                'created_at' => $n->created_at->toIso8601String(),
            ]);

        // 6. Running A/B tests
        $abTests = ABTest::whereIn('campaign_id', $campaignIds)
            ->where('status', ABTest::STATUS_RUNNING)
            ->latest('started_at')
            ->take(5)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'test_type' => $t->test_type,
                'status' => $t->status,
                'confidence_level' => $t->confidence_level,
                'started_at' => $t->started_at?->toIso8601String(),
                'variants' => $t->variants,
            ]);

        // 7. Competitive strategy summary
        $competitiveStrategy = $customer->competitive_strategy;
        $strategyUpdatedAt = $customer->competitive_strategy_updated_at?->toIso8601String();

        // 8. War Room pinned competitors
        $pinnedIds = $customer->war_room_competitors ?? [];
        $warRoomCompetitors = ! empty($pinnedIds)
            ? Competitor::whereIn('id', $pinnedIds)
                ->where('customer_id', $customer->id)
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'url' => $c->url,
                    'domain' => $c->domain,
                    'name' => $c->name,
                    'messaging_analysis' => $c->messaging_analysis,
                    'value_propositions' => $c->value_propositions,
                    'keywords_detected' => $c->keywords_detected,
                    'pricing_info' => $c->pricing_info,
                    'impression_share' => $c->impression_share,
                    'last_analyzed_at' => $c->last_analyzed_at?->toIso8601String(),
                    'is_analyzing' => is_null($c->messaging_analysis),
                ])
            : [];

        // 9. Gap analysis
        $gapAnalysis = $customer->war_room_gap_analysis;
        $gapAnalysisAt = $customer->war_room_gap_analysis_at?->toIso8601String();

        return Inertia::render('Strategy/WarRoom', [
            'canAccessWarRoom' => true,
            'health' => $health,
            'activities' => $activities,
            'recommendations' => $recommendations,
            'advisories' => $advisories,
            'performance' => $performance,
            'alerts' => $alerts,
            'abTests' => $abTests,
            'competitiveStrategy' => $competitiveStrategy,
            'strategyUpdatedAt' => $strategyUpdatedAt,
            'warRoomCompetitors' => $warRoomCompetitors,
            'gapAnalysis' => $gapAnalysis,
            'gapAnalysisAt' => $gapAnalysisAt,
        ]);
    }

    public function approveRecommendation(Request $request, Recommendation $recommendation)
    {
        // The id comes off the URL and Recommendation carries no tenant scope
        // (no customer_id column), so nothing before this filters by owner.
        // RecommendationPolicy walks campaign->customer_id.
        $this->authorize('update', $recommendation);

        if ($recommendation->source === 'competitor') {
            if (! ($recommendation->execution['can_apply'] ?? false)) {
                return back()->with('flash', ['type' => 'warning', 'message' => $recommendation->execution['blocked_reason'] ?? 'This action needs a specialist review.']);
            }
            $queued = Recommendation::whereKey($recommendation->id)->where('status', 'pending')->update(['status' => 'approved']);
            if ($queued) {
                \App\Jobs\ApplyCompetitiveRecommendation::dispatch($recommendation->id)->afterCommit();
            }

            return back()->with('flash', ['type' => 'success', 'message' => $queued ? 'Change queued. Its platform verification and results will appear in the competitor report.' : 'This action has already been reviewed.']);
        }

        return back()->with('flash', [
            'type' => 'warning',
            'message' => 'This suggestion is advisory only. No platform change is available from this page.',
        ]);
    }

    public function rejectRecommendation(Request $request, Recommendation $recommendation)
    {
        $this->authorize('update', $recommendation);

        Recommendation::whereKey($recommendation->id)->where('status', 'pending')->update(['status' => 'rejected']);

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Recommendation dismissed.',
        ]);
    }

    /**
     * Add a competitor URL to the War Room (max 3).
     */
    public function addCompetitor(Request $request)
    {
        $validated = $request->validate([
            // CrawlCompetitorWebsite fetches this from our servers, so a
            // "competitor" could otherwise be an address on our own network.
            'url' => ['required', 'url', 'max:500', new \App\Rules\SafePublicUrl],
        ]);

        $customer = $this->getActiveCustomer($request);
        if (! $customer) {
            return redirect()->route('customers.create');
        }

        $pinnedIds = $customer->war_room_competitors ?? [];

        if (count($pinnedIds) >= 3) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'You can pin up to 3 competitors. Remove one first.',
            ]);
        }

        $url = $validated['url'];
        $domain = Competitor::extractDomain($url);

        // Check for duplicate by domain
        $existing = Competitor::where('customer_id', $customer->id)
            ->where('domain', $domain)
            ->whereIn('id', $pinnedIds)
            ->first();

        if ($existing) {
            return redirect()->back()->with('flash', [
                'type' => 'warning',
                'message' => 'This competitor is already pinned.',
            ]);
        }

        // Create or reuse a competitor record
        $competitor = Competitor::firstOrCreate(
            ['customer_id' => $customer->id, 'domain' => $domain],
            [
                'url' => $url,
                'name' => $domain,
                'discovery_source' => 'war_room',
            ],
        );

        // Pin it
        $pinnedIds[] = $competitor->id;
        $customer->update(['war_room_competitors' => array_values($pinnedIds)]);

        // Dispatch analysis job
        AnalyzeWarRoomCompetitor::dispatch($customer, $competitor);

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => "Added {$domain} — analysis is running in the background.",
        ]);
    }

    /**
     * Remove a competitor from the War Room.
     */
    public function removeCompetitor(Request $request, Competitor $competitor)
    {
        $customer = $this->getActiveCustomer($request);
        if (! $customer) {
            return redirect()->route('customers.create');
        }

        // Ensure the competitor belongs to this customer
        if ($competitor->customer_id !== $customer->id) {
            abort(403);
        }

        $pinnedIds = $customer->war_room_competitors ?? [];
        $pinnedIds = array_values(array_filter($pinnedIds, fn ($id) => $id !== $competitor->id));
        $customer->update(['war_room_competitors' => $pinnedIds]);

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => "Removed {$competitor->domain} from War Room.",
        ]);
    }

    /**
     * Aggregate performance across all ad platforms for the given campaigns.
     */
    private function aggregatePerformance($campaignIds, $since): array
    {
        $models = [
            GoogleAdsPerformanceData::class,
            FacebookAdsPerformanceData::class,
            MicrosoftAdsPerformanceData::class,
            LinkedInAdsPerformanceData::class,
        ];

        $totals = [
            'impressions' => 0,
            'clicks' => 0,
            'cost' => 0,
            'conversions' => 0,
            'conversion_value' => 0,
        ];

        $daily = [];

        foreach ($models as $model) {
            $rows = $model::whereIn('campaign_id', $campaignIds)
                ->where('date', '>=', $since)
                ->get();

            foreach ($rows as $row) {
                $totals['impressions'] += $row->impressions ?? 0;
                $totals['clicks'] += $row->clicks ?? 0;
                $totals['cost'] += $row->cost ?? 0;
                $totals['conversions'] += $row->conversions ?? 0;
                $totals['conversion_value'] += $row->conversion_value ?? 0;

                $dateKey = $row->date->format('Y-m-d');
                if (! isset($daily[$dateKey])) {
                    $daily[$dateKey] = ['date' => $dateKey, 'impressions' => 0, 'clicks' => 0, 'cost' => 0, 'conversions' => 0];
                }
                $daily[$dateKey]['impressions'] += $row->impressions ?? 0;
                $daily[$dateKey]['clicks'] += $row->clicks ?? 0;
                $daily[$dateKey]['cost'] += $row->cost ?? 0;
                $daily[$dateKey]['conversions'] += $row->conversions ?? 0;
            }
        }

        $totals['ctr'] = $totals['impressions'] > 0 ? round(($totals['clicks'] / $totals['impressions']) * 100, 2) : 0;
        $totals['roas'] = $totals['cost'] > 0 ? round($totals['conversion_value'] / $totals['cost'], 2) : 0;

        ksort($daily);

        return [
            'totals' => $totals,
            'daily' => array_values($daily),
        ];
    }
}
