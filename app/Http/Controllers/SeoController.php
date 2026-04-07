<?php

namespace App\Http\Controllers;

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

        if (!$customer) {
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

        if (!$customer) {
            return redirect()->route('customers.create');
        }

        $validated = $request->validate([
            'url' => 'required|url|max:500',
        ]);

        RunSeoAudit::dispatch($customer->id, $validated['url']);

        return back()->with('success', 'SEO audit started. Results will appear shortly.');
    }

    public function auditDetail(Request $request, SeoAudit $audit)
    {
        $customer = $this->resolveCustomer($request);

        if (!$customer) {
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

        if (!$customer) {
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

        if (!$customer) {
            return redirect()->route('customers.create');
        }

        TrackKeywordRankings::dispatch($customer->id);

        return back()->with('success', 'Keyword rank tracking started.');
    }

    public function backlinks(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        if (!$customer) {
            return redirect()->route('customers.create');
        }
        $domain = $customer->website ? parse_url($customer->website, PHP_URL_HOST) : null;

        if (!$domain) {
            return Inertia::render('SEO/Backlinks', [
                'profile' => null,
                'domain' => null,
                'error' => 'Please set your website URL in customer settings first.',
            ]);
        }

        $service = new BacklinkAnalysisService($customer);
        $profile = $service->analyze($domain);

        return Inertia::render('SEO/Backlinks', [
            'profile' => $profile,
            'domain' => $domain,
        ]);
    }

    public function competitorComparison(Request $request)
    {
        $customer = $this->resolveCustomer($request);

        if (!$customer) {
            return redirect()->route('customers.create');
        }
        $domain = $customer->website ? parse_url($customer->website, PHP_URL_HOST) : null;

        $competitors = Competitor::where('customer_id', $customer->id)
            ->orderBy('updated_at', 'desc')
            ->get();

        return Inertia::render('SEO/Competitors', [
            'domain' => $domain,
            'competitors' => $competitors,
        ]);
    }
}
