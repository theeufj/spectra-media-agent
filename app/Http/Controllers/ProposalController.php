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
    public function create()
    {
        return Inertia::render('Proposals/Create');
    }

    /**
     * Store a new proposal and dispatch generation job.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'industry' => 'nullable|string|max:255',
            'website_url' => 'nullable|url|max:2048',
            'budget' => 'required|numeric|min:100|max:1000000',
            'goals' => 'nullable|string|max:2000',
            'platforms' => 'required|array|min:1',
            'platforms.*' => ['required', 'string', Rule::in(['Google Ads', 'Facebook & Instagram', 'Microsoft Ads', 'LinkedIn Ads', 'TikTok Ads'])],
        ]);

        $proposal = Proposal::create([
            'user_id' => $request->user()->id,
            'customer_id' => session('active_customer_id'),
            'client_name' => $validated['client_name'],
            'industry' => $validated['industry'],
            'website_url' => $validated['website_url'],
            'budget' => $validated['budget'],
            'goals' => $validated['goals'],
            'platforms' => $validated['platforms'],
            'status' => Proposal::STATUS_GENERATING,
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
        if ($proposal->user_id !== $request->user()->id) {
            abort(403);
        }

        return Inertia::render('Proposals/Show', [
            'proposal' => $proposal,
        ]);
    }

    /**
     * Check the status of a generating proposal (for polling).
     */
    public function status(Request $request, Proposal $proposal)
    {
        if ($proposal->user_id !== $request->user()->id) {
            abort(403);
        }

        return response()->json([
            'status' => $proposal->status,
            'proposal_data' => $proposal->isReady() ? $proposal->proposal_data : null,
        ]);
    }

    /**
     * Download the proposal as PDF.
     */
    public function exportPdf(Request $request, Proposal $proposal, ProposalPdfService $pdfService)
    {
        if ($proposal->user_id !== $request->user()->id) {
            abort(403);
        }

        if (!$proposal->isReady()) {
            abort(404, 'Proposal is not ready yet.');
        }

        $pdf = $pdfService->getPdfContent($proposal);

        if (!$pdf) {
            abort(500, 'Failed to generate PDF.');
        }

        $filename = str_replace(' ', '_', $proposal->client_name) . '_Proposal_' . now()->format('Y-m-d') . '.pdf';

        return response($pdf, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}
