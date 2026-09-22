<?php

namespace App\Http\Controllers;

use App\Jobs\RunCompetitorIntelligence;
use App\Jobs\RunSeoAudit;
use App\Jobs\TrackKeywordRankings;
use App\Models\Competitor;
use App\Models\SeoAudit;
use App\Models\SeoRanking;
use App\Services\SEO\BacklinkAnalysisService;
use App\Services\SEO\RankTrackingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SeoController extends Controller
{
    private function resolveCustomer(Request $request)
    {
        return $request->user()->customer ?? $request->user()->customers()->first();
    }

    public function index(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        if (! $customer) {
            return redirect()->route('customers.create');
        }

        $latestAudit = SeoAudit::where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->first();

        $rankingService = new RankTrackingService($customer);
        $rankingSummary = $rankingService->getSummary();

        $audits = SeoAudit::where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $topRankings = SeoRanking::where('customer_id', $customer->id)
            ->whereDate('date', now()->toDateString())
            ->whereNotNull('position')
            ->orderBy('position')
            ->limit(20)
            ->get();

        $competitors = Competitor::where('customer_id', $customer->id)
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get();

        return Inertia::render('SEO/Index', [
            'latestAudit' => $latestAudit,
            'audits' => $audits,
            'rankingSummary' => $rankingSummary,
            'topRankings' => $topRankings,
            'competitors' => $competitors,
            'domain' => $customer->website ? parse_url($customer->website, PHP_URL_HOST) : null,
        ]);
    }

    public function runAudit(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        if (! $customer) {
            return redirect()->route('customers.create');
        }

        $validated = $request->validate([
            // RunSeoAudit drives Browsershot with --no-sandbox and parses the
            // fetched HTML back into an audit the submitter reads, so an
            // unchecked host here is a read of anything on our network.
            'url' => ['required', 'url', 'max:500', new \App\Rules\SafePublicUrl],
        ]);

        RunSeoAudit::dispatch($customer->id, $validated['url']);

        return back()->with('success', 'SEO audit started. Results will appear shortly.');
    }

    public function auditDetail(Request $request, SeoAudit $audit)
    {
        $customer = $this->resolveCustomer($request);

        if (! $customer) {
            return redirect()->route('customers.create');
        }

        if ($audit->customer_id !== $customer->id) {
            abort(403);
        }

        return Inertia::render('SEO/Audit', [
            'audit' => $audit,
        ]);
    }

    public function rankings(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        if (! $customer) {
            return redirect()->route('customers.create');
        }

        $service = new RankTrackingService($customer);
        $summary = $service->getSummary();

        $rankings = SeoRanking::where('customer_id', $customer->id)
            ->whereDate('date', now()->toDateString())
            ->orderBy('position')
            ->get();

        // Get trend data for top keywords
        $trends = [];
        foreach ($rankings->take(10) as $ranking) {
            $trends[$ranking->keyword] = $service->getTrends($ranking->keyword, 30);
        }

        return Inertia::render('SEO/Rankings', [
            'summary' => $summary,
            'rankings' => $rankings,
            'trends' => $trends,
        ]);
    }

    public function trackKeywords(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        if (! $customer) {
            return redirect()->route('customers.create');
        }

        TrackKeywordRankings::dispatch($customer->id);

        return back()->with('success', 'Keyword rank tracking started.');
    }

    public function backlinks(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        if (! $customer) {
            return redirect()->route('customers.create');
        }
        $domain = BacklinkAnalysisService::domain($customer);

        if (! $domain) {
            return Inertia::render('SEO/Backlinks', [
                'profile' => null,
                'domain' => null,
                'error' => 'Please set your website URL in customer settings first.',
            ]);
        }

        $service = new BacklinkAnalysisService($customer);

        return Inertia::render('SEO/Backlinks', $service->report($domain));
    }

    public function backlinkStatus(Request $request)
    {
        $customer = $this->resolveCustomer($request);
        abort_unless($customer, 404);
        $domain = BacklinkAnalysisService::domain($customer);
        abort_unless($domain !== null, 422, 'Set your website URL before running an analysis.');

        return response()->json((new BacklinkAnalysisService($customer))->report($domain));
    }

    public function refreshBacklinks(Request $request)
    {
        $customer = $this->resolveCustomer($request);
        abort_unless($customer, 404);
        $domain = BacklinkAnalysisService::domain($customer);
        if (! $domain) {
            return back()->with('flash', ['type' => 'warning', 'message' => 'Set your website URL before running an analysis.']);
        }
        $key = (new BacklinkAnalysisService($customer))->key($domain);
        \Illuminate\Support\Facades\Cache::lock($key.':dispatch', 10)->get(function () use ($key, $customer, $domain) {
            $run = \Illuminate\Support\Facades\Cache::get($key.':run');
            if (in_array($run['status'] ?? '', ['queued', 'running'], true)) {
                return;
            }
            \Illuminate\Support\Facades\Cache::put($key.':run', ['status' => 'queued'], now()->addMinutes(10));
            \App\Jobs\RunBacklinkAnalysis::dispatch($customer->id, $domain);
        });

        return back()->with('flash', ['type' => 'success', 'message' => 'Backlink analysis queued. Results will update here.']);
    }

    public function competitorComparison(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        if (! $customer) {
            return redirect()->route('customers.create');
        }

        $canAccess = $request->user()->hasFeature('competitor_analysis');
        $domain = $customer->website ? parse_url($customer->website, PHP_URL_HOST) : null;

        $competitors = $canAccess
            ? Competitor::where('customer_id', $customer->id)
                ->orderBy('updated_at', 'desc')
                ->get()
            : collect();

        return Inertia::render('SEO/Competitors', [
            'domain' => $domain,
            'competitors' => $competitors,
            'canAccessCompetitors' => $canAccess,
            'competitiveStrategy' => $canAccess ? $customer->competitive_strategy : null,
            'strategyUpdatedAt' => $canAccess ? $customer->competitive_strategy_updated_at?->toIso8601String() : null,
            'lastAnalyzedAt' => $canAccess ? $customer->competitor_analysis_at?->toIso8601String() : null,
            'campaignActions' => $canAccess ? app(\App\Services\Competition\CompetitiveActionReport::class)->forCustomer($customer) : null,
        ]);
    }

    public function refreshCompetitors(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        if (! $customer) {
            return redirect()->route('customers.create');
        }

        if (! $request->user()->hasFeature('competitor_analysis')) {
            abort(403);
        }

        // Rate limit: once per 24 hours
        $lastRun = $customer->competitor_analysis_at;
        if ($lastRun && $lastRun->diffInHours(now()) < 24) {
            return back()->with('flash', [
                'type' => 'warning',
                'message' => 'Competitor analysis was run recently. Please wait 24 hours between refreshes.',
            ]);
        }

        RunCompetitorIntelligence::dispatch($customer, true);

        return back()->with('flash', [
            'type' => 'success',
            'message' => 'Competitor analysis started. Results will appear within a few minutes.',
        ]);
    }

    public function competitorActions(Request $request)
    {
        abort_unless($request->user()->hasFeature('competitor_analysis'), 403);
        $customer = $this->resolveCustomer($request);
        abort_unless($customer, 404);

        return response()->json(app(\App\Services\Competition\CompetitiveActionReport::class)->forCustomer($customer));
    }

    public function reviewCompetitorCampaigns(Request $request)
    {
        abort_unless($request->user()->hasFeature('competitor_analysis'), 403);
        $customer = $this->resolveCustomer($request);
        abort_unless($customer, 404);
        $key = 'competitive_review:'.$customer->id;
        $existing = \Illuminate\Support\Facades\Cache::get($key);
        if (in_array($existing['status'] ?? '', ['queued', 'running'], true)) {
            return back()->with('flash', ['type' => 'info', 'message' => 'Campaign review is already running.']);
        }
        \Illuminate\Support\Facades\Cache::put($key, ['status' => 'queued'], now()->addMinutes(20));
        \App\Jobs\ReviewCompetitiveCampaigns::dispatch($customer->id);

        return back()->with('flash', ['type' => 'success', 'message' => 'Reviewing competitor opportunities for your serving campaigns.']);
    }
}
