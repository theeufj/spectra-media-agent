<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Keyword;
use App\Models\NegativeKeywordList;
use App\Models\KeywordQualityScore;
use App\Services\GoogleAds\KeywordResearch\KeywordResearchService;
use App\Services\KeywordClusteringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class KeywordController extends Controller
{
    public function index(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');

        $keywords = Keyword::where('customer_id', $customer->id)
            ->with('campaign:id,name')
            ->orderByDesc('updated_at')
            ->paginate(50);

        $stats = [
            'total' => Keyword::where('customer_id', $customer->id)->count(),
            'active' => Keyword::where('customer_id', $customer->id)->where('status', 'active')->count(),
            'low_qs' => Keyword::where('customer_id', $customer->id)->whereNotNull('quality_score')->where('quality_score', '<', 5)->count(),
        ];

        $qsTrends = KeywordQualityScore::where('customer_id', $customer->id)
            ->where('recorded_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(recorded_at) as date, AVG(quality_score) as avg_qs, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return Inertia::render('Keywords/Index', [
            'keywords' => $keywords,
            'stats' => $stats,
            'qsTrends' => $qsTrends,
        ]);
    }

    public function research(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');

        return Inertia::render('Keywords/Research', [
            'customer' => $customer->only('id', 'name', 'industry', 'website_url'),
        ]);
    }

    public function doResearch(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return back()->with('flash', ['type' => 'error', 'message' => 'No active customer.']);

        $validated = $request->validate([
            'seed_keywords' => 'nullable|string|max:500',
            'competitor_url' => 'nullable|url|max:500',
            'landing_page' => 'nullable|url|max:500',
            'max_keywords' => 'nullable|integer|min:5|max:50',
        ]);

        $customerId = $customer->google_ads_customer_id;
        if (!$customerId) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Google Ads account required for keyword research.']);
        }

        try {
            $service = new KeywordResearchService($customer);
            $results = $service->research(
                $customerId,
                $customer->name,
                $customer->industry,
                $validated['landing_page'] ?? $customer->website_url,
                'languageConstants/1000',
                [],
                $validated['max_keywords'] ?? 20
            );

            // AI clustering
            if (!empty($results['keywords'])) {
                $clusterService = new KeywordClusteringService();
                $clusters = $clusterService->cluster($results['keywords']);
                $results['clusters'] = $clusters['clusters'] ?? [];
            }

            return back()->with('flash', [
                'type' => 'success',
                'message' => 'Found ' . count($results['keywords'] ?? []) . ' keywords.',
            ])->with('research_results', $results);

        } catch (\Exception $e) {
            Log::error('KeywordController: Research failed', ['error' => $e->getMessage()]);
            return back()->with('flash', ['type' => 'error', 'message' => 'Keyword research failed: ' . $e->getMessage()]);
        }
    }

    public function addToCampaign(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return back();

        $validated = $request->validate([
            'keywords' => 'required|array|min:1',
            'keywords.*.text' => 'required|string|max:200',
            'keywords.*.match_type' => 'required|string|in:BROAD,PHRASE,EXACT',
            'campaign_id' => 'nullable|integer|exists:campaigns,id',
            'source' => 'nullable|string|max:50',
        ]);

        $created = 0;
        foreach ($validated['keywords'] as $kw) {
            Keyword::updateOrCreate(
                [
                    'customer_id' => $customer->id,
                    'keyword_text' => $kw['text'],
                    'campaign_id' => $validated['campaign_id'] ?? null,
                ],
                [
                    'match_type' => $kw['match_type'],
                    'status' => 'active',
                    'source' => $validated['source'] ?? 'research',
                    'avg_monthly_searches' => $kw['avg_monthly_searches'] ?? null,
                    'competition_index' => $kw['competition_index'] ?? null,
                    'intent' => $kw['intent'] ?? null,
                    'cluster' => $kw['cluster'] ?? null,
                    'funnel_stage' => $kw['funnel_stage'] ?? null,
                    'added_by' => $request->user()->id,
                ]
            );
            $created++;
        }

        return back()->with('flash', [
            'type' => 'success',
            'message' => "Added {$created} keywords to your portfolio.",
        ]);
    }

    public function bulkAction(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return back();

        $validated = $request->validate([
            'keyword_ids' => 'required|array|min:1',
            'keyword_ids.*' => 'integer|exists:keywords,id',
            'action' => 'required|string|in:pause,activate,remove,change_match_type',
            'match_type' => 'nullable|string|in:BROAD,PHRASE,EXACT',
        ]);

        $query = Keyword::where('customer_id', $customer->id)->whereIn('id', $validated['keyword_ids']);

        switch ($validated['action']) {
            case 'pause':
                $query->update(['status' => 'paused']);
                break;
            case 'activate':
                $query->update(['status' => 'active']);
                break;
            case 'remove':
                $query->update(['status' => 'removed']);
                break;
            case 'change_match_type':
                if (!empty($validated['match_type'])) {
                    $query->update(['match_type' => $validated['match_type']]);
                }
                break;
        }

        return back()->with('flash', ['type' => 'success', 'message' => 'Keywords updated.']);
    }

    public function competitorGap(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');

        $competitors = $customer->competitors()
            ->whereNotNull('keywords_detected')
            ->latest('analyzed_at')
            ->take(5)
            ->get();

        $ourKeywords = Keyword::where('customer_id', $customer->id)
            ->where('status', 'active')
            ->pluck('keyword_text')
            ->map(fn($k) => strtolower($k))
            ->toArray();

        $gaps = [];
        foreach ($competitors as $competitor) {
            $detected = $competitor->keywords_detected ?? [];
            foreach ($detected as $kw) {
                $kwLower = strtolower($kw);
                if (!in_array($kwLower, $ourKeywords)) {
                    $gaps[$kwLower] = [
                        'keyword' => $kw,
                        'found_on' => $gaps[$kwLower]['found_on'] ?? [],
                    ];
                    $gaps[$kwLower]['found_on'][] = $competitor->domain;
                }
            }
        }

        $gaps = array_values($gaps);
        usort($gaps, fn($a, $b) => count($b['found_on']) <=> count($a['found_on']));

        return Inertia::render('Keywords/CompetitorGap', [
            'gaps' => array_slice($gaps, 0, 100),
            'competitors' => $competitors->map->only('id', 'domain', 'name'),
            'ourKeywordCount' => count($ourKeywords),
        ]);
    }

    public function negativeLists(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');

        $lists = NegativeKeywordList::where('customer_id', $customer->id)
            ->orderByDesc('updated_at')
            ->get();

        return Inertia::render('Keywords/NegativeLists', [
            'lists' => $lists,
        ]);
    }

    public function storeNegativeList(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return back();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'keywords' => 'required|array|min:1',
            'keywords.*' => 'string|max:200',
        ]);

        NegativeKeywordList::create([
            'customer_id' => $customer->id,
            'name' => $validated['name'],
            'keywords' => $validated['keywords'],
            'applied_to_campaigns' => [],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Negative keyword list created.']);
    }

    public function updateNegativeList(Request $request, NegativeKeywordList $list)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer || $list->customer_id !== $customer->id) return back();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'keywords' => 'sometimes|array',
            'keywords.*' => 'string|max:200',
            'applied_to_campaigns' => 'sometimes|array',
            'applied_to_campaigns.*' => 'integer',
        ]);

        $list->update($validated);

        return back()->with('flash', ['type' => 'success', 'message' => 'Negative keyword list updated.']);
    }

    public function destroyNegativeList(Request $request, NegativeKeywordList $list)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer || $list->customer_id !== $customer->id) return back();

        $list->delete();

        return back()->with('flash', ['type' => 'success', 'message' => 'Negative keyword list deleted.']);
    }

    protected function getActiveCustomer(Request $request): ?Customer
    {
        $user = $request->user();
        $customerId = session('active_customer_id');

        if ($customerId) {
            return Customer::where('id', $customerId)
                ->whereHas('users', fn($q) => $q->where('user_id', $user->id))
                ->first();
        }

        return $user->customers()->first();
    }
}
