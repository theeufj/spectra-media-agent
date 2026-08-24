<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdSpendTransaction;
use App\Models\Customer;
use App\Models\FacebookAdsPerformanceData;
use App\Models\GoogleAdsPerformanceData;
use App\Services\ActivityLogger;
use App\Services\Customers\DeactivateCustomerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Admin views of customer accounts and their per-platform ad account identifiers.
 *
 * Extracted from the former 1,000-line AdminController.
 */
class CustomerController extends Controller
{
    public function customersIndex()
    {
        $customers = Customer::with(['users.assignedPlan', 'campaigns'])->withCount('campaigns')->get();
        $plans = \App\Models\Plan::active()->ordered()->get();

        // Row-level coverage lights, same rules as the workspace page.
        // Grouped aggregates rather than per-customer queries — this table
        // lists every customer.
        $guidelines = \App\Models\BrandGuideline::query()
            ->selectRaw('customer_id, max(extraction_quality_score) as quality, bool_or(user_verified) as verified')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id')
            ->map(fn ($row) => [
                'quality' => (int) $row->getAttribute('quality'),
                'verified' => (bool) $row->getAttribute('verified'),
            ]);
        $kbCounts = \App\Models\KnowledgeBase::selectRaw('customer_id, count(*) as c')->groupBy('customer_id')->pluck('c', 'customer_id');
        $keywordCounts = \App\Models\Keyword::selectRaw('customer_id, count(*) as c')->groupBy('customer_id')->pluck('c', 'customer_id');
        $signedOffCampaigns = \App\Models\Campaign::whereHas('strategies', fn ($q) => $q->whereNotNull('signed_off_at'))
            ->selectRaw('customer_id, count(*) as c')->groupBy('customer_id')->pluck('c', 'customer_id');
        $adCopyCounts = \App\Models\AdCopy::join('strategies', 'strategies.id', '=', 'ad_copies.strategy_id')
            ->join('campaigns', 'campaigns.id', '=', 'strategies.campaign_id')
            ->selectRaw('campaigns.customer_id, count(*) as c')->groupBy('campaigns.customer_id')->pluck('c', 'customer_id');
        $imageCounts = \App\Models\ImageCollateral::where('image_collaterals.is_active', true)
            ->join('campaigns', 'campaigns.id', '=', 'image_collaterals.campaign_id')
            ->selectRaw('campaigns.customer_id, count(*) as c')->groupBy('campaigns.customer_id')->pluck('c', 'customer_id');

        $customers->each(function ($customer) use ($guidelines, $kbCounts, $keywordCounts, $signedOffCampaigns, $adCopyCounts, $imageCounts) {
            $guideline = $guidelines->get($customer->id);
            $kb = (int) ($kbCounts[$customer->id] ?? 0);
            $keywords = (int) ($keywordCounts[$customer->id] ?? 0);
            $campaignTotal = $customer->campaigns_count;
            $campaignsSigned = (int) ($signedOffCampaigns[$customer->id] ?? 0);
            $copies = (int) ($adCopyCounts[$customer->id] ?? 0);
            $images = (int) ($imageCounts[$customer->id] ?? 0);

            $customer->setAttribute('coverage', [
                'brand' => ! $guideline ? 'red' : (($guideline['verified'] && $guideline['quality'] >= 7) ? 'green' : 'orange'),
                'knowledge' => $kb >= 5 ? 'green' : ($kb > 0 ? 'orange' : 'red'),
                'campaigns' => $campaignTotal === 0 ? 'red' : ($campaignsSigned >= $campaignTotal ? 'green' : 'orange'),
                'creative' => ($copies > 0 && $images > 0) ? 'green' : (($copies > 0 || $images > 0) ? 'orange' : 'red'),
                'keywords' => $keywords >= 5 ? 'green' : ($keywords > 0 ? 'orange' : 'red'),
            ]);
        });

        return Inertia::render('Admin/Customers', [
            'customers' => $customers,
            'plans' => $plans,
        ]);
    }

    /**
     * Show detailed view of a customer including campaigns, strategies, and collateral.
     */
    public function customerShow(Customer $customer)
    {
        $customer->load([
            'users',
            'campaigns' => function ($query) {
                $query->with(['strategies' => function ($q) {
                    $q->withCount(['adCopies', 'imageCollaterals', 'videoCollaterals']);
                }]);
            },
        ]);

        $credit = $customer->adSpendCredit;
        $campaignIds = $customer->campaigns()->pluck('id');

        $googleSpend = GoogleAdsPerformanceData::whereIn('campaign_id', $campaignIds)->sum('cost');
        $facebookSpend = FacebookAdsPerformanceData::whereIn('campaign_id', $campaignIds)->sum('cost');
        $totalActualSpend = round($googleSpend + $facebookSpend, 2);
        $totalDebited = $credit ? round($credit->transactions()->whereIn('type', [AdSpendTransaction::TYPE_DEDUCTION, AdSpendTransaction::TYPE_ADJUSTMENT])->sum('amount'), 2) : 0;

        return Inertia::render('Admin/CustomerDetail', [
            'customer' => $customer,
            'bm_configured' => app(\App\Services\FacebookAds\BusinessManagerService::class)->isConfigured(),
            'adSpendCredit' => $credit ? [
                'current_balance' => (float) $credit->current_balance,
                'initial_credit_amount' => (float) $credit->initial_credit_amount,
                'status' => $credit->status,
                'payment_status' => $credit->payment_status,
                'total_actual_spend' => $totalActualSpend,
                'total_debited' => $totalDebited,
                'unreconciled' => round($totalActualSpend - $totalDebited, 2),
            ] : null,
        ]);
    }

    /**
     * Everything the customer's AI workspace has produced, on one page:
     * brand guidelines, campaign strategies, ad copy, and creative. This is
     * the admin's monitoring view — spotting a bad extraction, an off-brand
     * image, or a strategy that misread the business before the customer
     * (or their audience) does.
     */
    public function customerWorkspace(Customer $customer)
    {
        $brandGuideline = \App\Models\BrandGuideline::where('customer_id', $customer->id)
            ->latest('extracted_at')
            ->first();

        // The heavy JSON columns (execution_plan/result) stay out of the
        // payload — this page is for reviewing content, not debugging runs.
        $campaigns = $customer->campaigns()
            ->latest()
            ->with([
                'strategies' => function ($q) {
                    $q->select([
                        'id', 'campaign_id', 'platform', 'campaign_type', 'daily_budget',
                        'status', 'signed_off_at', 'deployed_at', 'deployment_status',
                        'deployment_error', 'ad_copy_strategy', 'imagery_strategy',
                        'video_strategy', 'bidding_strategy', 'created_at',
                    ])->with([
                        'adCopies',
                        // Inactive rows included: superseded creative (a refined
                        // image deactivates its parent) is exactly what an admin
                        // reviewing history needs to see.
                        'imageCollaterals' => fn ($iq) => $iq->latest(),
                        'videoCollaterals' => fn ($vq) => $vq->latest(),
                    ]);
                },
                // Campaign-level media: wizard uploads, AI seeds, shared videos.
                'imageCollaterals' => fn ($iq) => $iq->whereNull('strategy_id')->latest(),
                'videoCollaterals' => fn ($vq) => $vq->whereNull('strategy_id')->latest(),
            ])
            ->get();

        $harvested = \App\Models\HarvestedAsset::where('customer_id', $customer->id)
            ->whereIn('status', ['classified', 'processed'])
            ->latest()
            ->limit(24)
            ->get(['id', 'cloudfront_url', 'classification', 'source_page_url', 'status', 'created_at']);

        // The knowledge base is what every campaign is written FROM — a bad
        // crawl surfaces here first. Excerpts only; full content by request.
        $knowledgePages = \App\Models\KnowledgeBase::where('customer_id', $customer->id)
            ->latest()
            ->limit(150)
            ->get(['id', 'url', 'source_type', 'original_filename', 'created_at', \Illuminate\Support\Facades\DB::raw('length(content) as content_length'), \Illuminate\Support\Facades\DB::raw('left(content, 300) as excerpt')]);

        $keywords = \App\Models\Keyword::where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'campaign_id', 'keyword_text', 'match_type', 'status', 'source', 'intent', 'funnel_stage', 'quality_score']);

        return Inertia::render('Admin/CustomerWorkspace', [
            'customer' => $customer->only(['id', 'name', 'business_name', 'website', 'country', 'currency_code', 'created_at']),
            'brandGuideline' => $brandGuideline,
            'campaigns' => $campaigns,
            'harvestedAssets' => $harvested,
            'knowledgePages' => $knowledgePages,
            'creativeBriefs' => \App\Models\CreativeBrief::where('customer_id', $customer->id)->latest()->limit(50)->get(),
            'personas' => \App\Models\Persona::where('customer_id', $customer->id)->latest()->get(),
            'proposals' => \App\Models\Proposal::where('customer_id', $customer->id)->latest()
                ->get(['id', 'client_name', 'industry', 'budget', 'goals', 'platforms', 'status', 'created_at']),
            'keywords' => $keywords,
            'negativeKeywordLists' => \App\Models\NegativeKeywordList::where('customer_id', $customer->id)->get(),
            'products' => \App\Models\Product::where('customer_id', $customer->id)->latest()->limit(12)
                ->get(['id', 'title', 'image_link', 'price', 'sale_price', 'currency_code', 'availability', 'brand']),
            'seoAudits' => \App\Models\SeoAudit::where('customer_id', $customer->id)->latest()->limit(5)
                ->get(['id', 'url', 'score', 'created_at']),
            'landingPageAudits' => \App\Models\LandingPageAudit::where('customer_id', $customer->id)->latest()->limit(5)
                ->get(['id', 'url', 'message_match_score', 'cta_count', 'primary_cta', 'created_at']),
            'knowledge' => [
                'pages' => \App\Models\KnowledgeBase::where('customer_id', $customer->id)->count(),
                'last_crawled_at' => \App\Models\KnowledgeBase::where('customer_id', $customer->id)->latest()->value('created_at'),
                'harvested_total' => \App\Models\HarvestedAsset::where('customer_id', $customer->id)->count(),
                'keywords_total' => \App\Models\Keyword::where('customer_id', $customer->id)->count(),
                'products_total' => \App\Models\Product::where('customer_id', $customer->id)->count(),
            ],
        ]);
    }

    /**
     * Show performance dashboard for a customer (admin view).
     */
    public function customerDashboard(Customer $customer)
    {
        $customer->load('users');
        $campaigns = $customer->campaigns()->orderBy('created_at', 'desc')->get();

        return Inertia::render('Admin/CustomerDashboard', [
            'customer' => $customer,
            'campaigns' => $campaigns,
            'defaultCampaign' => $campaigns->first(),
        ]);
    }

    /**
     * Update the Facebook Ad Account ID for a customer (admin only).
     */
    public function updateCustomerFacebook(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'facebook_ads_account_id' => 'nullable|string|max:50',
            'facebook_page_url' => 'nullable|string|max:500',
        ]);

        $updates = [
            'facebook_ads_account_id' => $validated['facebook_ads_account_id'] ?: null,
            // Mark as BM-managed whenever an admin assigns an account ID — this is the
            // gate that allows FacebookAdsExecutionAgent to deploy campaigns.
            'facebook_bm_owned' => ! empty($validated['facebook_ads_account_id']),
        ];

        if (! empty($validated['facebook_page_url'])) {
            $parsed = Customer::parseFacebookPageUrl($validated['facebook_page_url']);
            if ($parsed) {
                $updates['facebook_page_id'] = $parsed['page_id'];
                if ($parsed['page_name']) {
                    $updates['facebook_page_name'] = $parsed['page_name'];
                }
            }
        }

        $customer->update($updates);

        Log::info('Admin updated Facebook settings', [
            'customer_id' => $customer->id,
            'facebook_ads_account_id' => $customer->facebook_ads_account_id,
            'facebook_page_id' => $customer->facebook_page_id,
        ]);

        return redirect()->back()->with('success', 'Facebook Ad Account ID updated.');
    }

    /**
     * Update the Microsoft Ads IDs for a customer (admin only).
     */
    public function updateCustomerMicrosoft(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'microsoft_ads_customer_id' => 'nullable|string|max:50',
            'microsoft_ads_account_id' => 'nullable|string|max:50',
        ]);

        $customer->update([
            'microsoft_ads_customer_id' => $validated['microsoft_ads_customer_id'] ?: null,
            'microsoft_ads_account_id' => $validated['microsoft_ads_account_id'] ?: null,
        ]);

        Log::info('Admin updated Microsoft Ads settings', [
            'customer_id' => $customer->id,
            'microsoft_ads_customer_id' => $customer->microsoft_ads_customer_id,
            'microsoft_ads_account_id' => $customer->microsoft_ads_account_id,
        ]);

        return redirect()->back()->with('success', 'Microsoft Ads account updated.');
    }

    /**
     * Update the Google Ads IDs for a customer (admin only).
     * Used when Standard Access is pending and sub-accounts are created manually in Google Ads UI.
     */
    public function updateCustomerGoogle(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'google_ads_customer_id' => 'nullable|string|max:50',
            'google_ads_manager_customer_id' => 'nullable|string|max:50',
        ]);

        // Strip dashes (Google Ads UI shows xxx-xxx-xxxx but API uses digits only)
        $customerId = $validated['google_ads_customer_id']
            ? preg_replace('/[^0-9]/', '', $validated['google_ads_customer_id'])
            : null;
        $managerId = $validated['google_ads_manager_customer_id']
            ? preg_replace('/[^0-9]/', '', $validated['google_ads_manager_customer_id'])
            : null;

        // Default manager to active MCC if not provided but customer ID is set
        if ($customerId && ! $managerId) {
            $managerId = config('googleads.mcc_customer_id');
        }

        $customer->update([
            'google_ads_customer_id' => $customerId,
            'google_ads_manager_customer_id' => $managerId,
        ]);

        // Trigger conversion tracking setup when a Google Ads account is connected for the first time
        if ($customerId && ! $customer->conversion_action_id) {
            \App\Jobs\SetupConversionTracking::dispatch($customer)->delay(now()->addSeconds(10));
        }

        Log::info('Admin updated Google Ads settings', [
            'customer_id' => $customer->id,
            'google_ads_customer_id' => $customer->google_ads_customer_id,
            'google_ads_manager_customer_id' => $customer->google_ads_manager_customer_id,
        ]);

        return redirect()->back()->with('success', 'Google Ads account updated.');
    }

    /**
     * Delete a customer, after stopping everything they are spending.
     *
     * The order matters more than the deletion does. This used to be a hard
     * delete with nothing behind it, so the campaigns on every platform kept
     * running and kept charging while the records identifying them were
     * destroyed — the action most likely to be taken *because* someone wanted
     * the spending to stop was the one that removed the means to stop it.
     *
     * A customer whose campaigns could not all be paused is not deleted. Being
     * told why is more useful than a tidy customer list and an unexplained bill.
     */
    public function deleteCustomer(Request $request, Customer $customer, DeactivateCustomerService $deactivator)
    {
        // Typing the name is the confirmation. A UI dialog is advice; this is
        // the part an accidental request cannot satisfy.
        if ($request->input('confirm_name') !== $customer->name) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Type the customer name exactly to confirm deletion.',
            ]);
        }

        $result = $deactivator->pauseAllCampaigns($customer);

        if ($result['failed'] > 0) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => "Not deleted — {$result['failed']} campaign(s) could not be paused and would keep spending: "
                    .implode(' | ', array_slice($result['errors'], 0, 3)),
            ]);
        }

        ActivityLogger::customer('deleted', $customer);

        Log::warning('Admin deleted customer', [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'admin_id' => $request->user()?->id,
            'campaigns_paused' => $result['paused'],
        ]);

        // Soft delete — the trail survives, and the customer can be restored.
        $customer->delete();

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => "Deleted \"{$customer->name}\" after pausing {$result['paused']} campaign(s).",
        ]);
    }

    /**
     * Bring back a soft-deleted customer.
     *
     * Their campaigns stay paused: restoring the record should not restart
     * spending on its own.
     */
    public function restoreCustomer(int $customerId)
    {
        $customer = Customer::withTrashed()->findOrFail($customerId);
        $customer->restore();

        ActivityLogger::customer('restored', $customer);

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => "Restored \"{$customer->name}\". Their campaigns remain paused.",
        ]);
    }
}
