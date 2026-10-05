<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateProposal;
use App\Models\Proposal;
use App\Services\ProposalPdfService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ProposalController extends Controller
{
    /**
     * List all proposals for the authenticated user.
     */
    public function index(Request $request)
    {
        $proposals = Proposal::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Proposals/Index', [
            'proposals' => $proposals,
        ]);
    }

    /**
     * Show the create proposal form.
     */
    public function create(Request $request)
    {
        return Inertia::render('Proposals/Create', ['currencyCode' => $this->getActiveCustomer($request)->currency_code ?? 'USD']);
    }

    /**
     * Store a new proposal and dispatch generation job.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'industry' => 'nullable|string|max:255',
            'website_url' => ['nullable', 'url', 'max:2048', new \App\Rules\SafePublicUrl],
            'budget' => 'required|numeric|min:100|max:1000000',
            'goals' => 'nullable|string|max:2000',
            'platforms' => 'required|array|min:1',
            'platforms.*' => ['required', 'string', Rule::in(['Google Ads', 'Facebook & Instagram', 'Microsoft Ads', 'LinkedIn Ads', 'TikTok Ads'])],
        ]);

        // Not session('active_customer_id') alone: that is null before a
        // customer is selected, which orphans the proposal from the tenant that
        // created it. Proposal is tenant-scoped, and a NULL customer_id matches
        // no tenant's IN — the row would be unreadable to its own author the
        // moment the redirect below landed, and unreachable forever after. The
        // proposals routes carry no ensureUserHasCustomer, so a subscriber who
        // has not onboarded yet reaches this. Refuse before anything is written
        // or queued.
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return back()->with('flash', [
                'type' => 'error',
                'message' => 'No active customer selected. Set up a customer account before generating a proposal.',
            ]);
        }

        $proposal = Proposal::create([
            'user_id' => $request->user()->id,
            'customer_id' => $customer->id,
            'client_name' => $validated['client_name'],
            'industry' => $validated['industry'] ?? null,
            'website_url' => $validated['website_url'] ?? null,
            'budget' => $validated['budget'],
            'currency_code' => $customer->currency_code,
            'goals' => $validated['goals'] ?? null,
            'platforms' => $validated['platforms'],
            'status' => Proposal::STATUS_GENERATING,
            'generation_started_at' => now(),
        ]);

        GenerateProposal::dispatch($proposal);

        return redirect()->route('proposals.show', $proposal)
            ->with('message', 'Proposal generation started! This usually takes 1-2 minutes.');
    }

    /**
     * Show a proposal (preview or generating state).
     */
    public function show(Request $request, Proposal $proposal)
    {
        $this->authorize('view', $proposal);

        return Inertia::render('Proposals/Show', [
            'proposal' => $proposal,
        ]);
    }

    /**
     * Check the status of a generating proposal (for polling).
     */
    public function status(Request $request, Proposal $proposal)
    {
        $this->authorize('view', $proposal);
        if ($proposal->isGenerating() && $proposal->updated_at->lt(now()->subMinutes(30))) {
            $proposal->markFailed('This generation stopped updating. Your inputs are saved; retry to continue.');
        }

        return response()->json([
            'status' => $proposal->status,
            'generation_step' => $proposal->generation_step,
            'error' => $proposal->error,
            'updated_at' => $proposal->updated_at?->toIso8601String(),
            'proposal_data' => $proposal->isReady() ? $proposal->proposal_data : null,
        ]);
    }

    public function retry(Request $request, Proposal $proposal)
    {
        $this->authorize('update', $proposal);
        \Illuminate\Support\Facades\DB::transaction(function () use ($proposal) {
            $locked = Proposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            if ($locked->isGenerating() && $locked->updated_at->gt(now()->subMinutes(30))) {
                return;
            }
            if ($locked->isReady()) {
                return;
            }
            $locked->update(['status' => Proposal::STATUS_GENERATING, 'generation_step' => 'queued', 'generation_started_at' => now(), 'error' => null]);
            GenerateProposal::dispatch($locked)->afterCommit();
        });

        return back()->with('flash', ['type' => 'success', 'message' => 'Your saved proposal is queued for generation.']);
    }

    /**
     * Download the proposal as PDF.
     */
    public function exportPdf(Request $request, Proposal $proposal, ProposalPdfService $pdfService)
    {
        $this->authorize('view', $proposal);

        if (! $proposal->isReady()) {
            abort(404, 'Proposal is not ready yet.');
        }

        try {
            $pdf = $pdfService->getPdfContent($proposal);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('flash', ['type' => 'error', 'message' => 'The PDF could not be prepared. Your proposal is saved; try downloading again.']);
        }

        if (! $pdf) {
            return back()->with('flash', ['type' => 'error', 'message' => 'The PDF could not be prepared. Your proposal is saved; try downloading again.']);
        }

        $filename = str_replace(' ', '_', $proposal->client_name).'_Proposal_'.now()->format('Y-m-d').'.pdf';

        return response($pdf, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }
}
