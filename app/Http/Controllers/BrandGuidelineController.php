<?php

namespace App\Http\Controllers;

use App\Models\BrandGuideline;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Spatie\Browsershot\Browsershot;

class BrandGuidelineController extends Controller
{
    /**
     * Display the brand guidelines for the authenticated user's customer.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Get the active customer from session
        // getActiveCustomer() recovers from a session pointing at a customer
        // this user cannot reach — a deleted account, a removed membership, a
        // session that outlived the row — instead of 404ing on it.
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return redirect()->route('quick-start');
        }

        $brandGuideline = $customer->brandGuideline;

        return Inertia::render('BrandGuidelines/Index', [
            'brandGuideline' => $brandGuideline,
            'customer' => $customer->only(['id', 'uuid', 'name', 'website']),
            'canEdit' => true, // You can add permission logic here
        ]);
    }

    /**
     * Update the brand guidelines.
     */
    public function update(Request $request, BrandGuideline $brandGuideline)
    {
        // BrandGuidelinePolicy has been registered since this feature shipped
        // and was never once invoked; this controller hand-rolled the pivot
        // query instead.
        $this->authorize('update', $brandGuideline);

        $validated = $request->validate([
            'brand_voice' => 'nullable|array',
            'brand_voice.primary_voice' => 'nullable|string|max:255',
            'brand_voice.voice_descriptors' => 'nullable|array',
            'brand_voice.voice_descriptors.*' => 'string|max:100',

            'tone_attributes' => 'nullable|array',
            'tone_attributes.primary_tones' => 'nullable|array',
            'tone_attributes.primary_tones.*' => 'string|max:100',
            'tone_attributes.contextual_tones' => 'nullable|array',

            'color_palette' => 'nullable|array',
            'color_palette.primary_colors' => 'nullable|array',
            'color_palette.secondary_colors' => 'nullable|array',
            'color_palette.accent_colors' => 'nullable|array',

            'typography' => 'nullable|array',
            'typography.primary_font' => 'nullable|string|max:100',
            'typography.secondary_font' => 'nullable|string|max:100',
            'typography.font_context' => 'nullable|string|max:500',

            'visual_style' => 'nullable|array',
            'visual_style.overall_aesthetic' => 'nullable|string|max:255',
            'visual_style.imagery_style' => 'nullable|string|max:255',
            'visual_style.description' => 'nullable|string|max:1000',

            'messaging_themes' => 'nullable|array',
            'messaging_themes.primary_themes' => 'nullable|array',
            'messaging_themes.primary_themes.*' => 'string|max:255',
            'messaging_themes.emotional_appeal' => 'nullable|string|max:255',
            'messaging_themes.proof_points' => 'nullable|string|max:1000',

            'unique_selling_propositions' => 'nullable|array',
            'unique_selling_propositions.*' => 'string|max:500',

            'target_audience' => 'nullable|array',
            'target_audience.demographics' => 'nullable|string|max:500',
            'target_audience.psychographics' => 'nullable|string|max:500',
            'target_audience.pain_points' => 'nullable|array',
            'target_audience.aspirations' => 'nullable|array',

            'brand_personality' => 'nullable|array',
            'brand_personality.traits' => 'nullable|array',
            'brand_personality.traits.*' => 'string|max:100',
            'brand_personality.archetype' => 'nullable|string|max:100',
            'brand_personality.communication_style' => 'nullable|string|max:255',

            'competitor_differentiation' => 'nullable|array',
            'competitor_differentiation.differentiation_points' => 'nullable|array',
            'competitor_differentiation.differentiation_points.*' => 'string|max:500',
            'competitor_differentiation.competitive_advantage' => 'nullable|string|max:500',

            'do_not_use' => 'nullable|array',
            'do_not_use.*' => 'string|max:255',

            'service_lines' => 'nullable|array',
            'service_lines.*.name' => 'nullable|string|max:255',
            'service_lines.*.description' => 'nullable|string|max:1000',
            'service_lines.*.target_audience' => 'nullable|string|max:500',
            'service_lines.*.messaging_themes' => 'nullable|array',
            'service_lines.*.messaging_themes.*' => 'string|max:255',
            'service_lines.*.content_volume' => 'nullable|string|in:high,medium,low',
            'service_lines.*.pain_points' => 'nullable|array',
            'service_lines.*.pain_points.*' => 'string|max:255',
        ]);

        $brandGuideline->update($validated);

        // Mark as user-verified since they edited it
        $brandGuideline->update(['user_verified' => true]);

        Log::info('Brand guidelines updated by user', [
            'user_id' => $request->user()->id,
            // Off the guideline, not the user: User::$customer resolves the
            // *primary* customer, which is not necessarily the one this
            // guideline belongs to.
            'customer_id' => $brandGuideline->customer_id,
            'brand_guideline_id' => $brandGuideline->id,
        ]);

        return back()->with('success', 'Brand guidelines updated successfully!');
    }

    /**
     * Mark brand guidelines as verified by the user.
     */
    public function verify(Request $request, BrandGuideline $brandGuideline)
    {
        $this->authorize('update', $brandGuideline);

        $brandGuideline->update(['user_verified' => true]);

        // The onboarding review flow signs off and moves straight into
        // campaign creation — to the auto-generated first campaign when one
        // exists, otherwise the wizard. Plain verifies stay on the page.
        if ($request->boolean('continue')) {
            // Read the owner off the guideline rather than the session: the
            // policy above has already established that it is the caller's.
            /** @var \App\Models\Customer $owner */
            $owner = $brandGuideline->customer;
            if ($owner->service_type === 'setup_only' && ! $owner->isPaidSetupOnly()) {
                return redirect()->route('subscription.pricing')
                    ->with('success', 'Brand profile confirmed — your one-time setup payment starts the campaign build.');
            }

            $autoCampaign = $owner->campaigns()
                ->whereNotNull('auto_generated_at')
                ->orderBy('id')
                ->first();

            /*
             * The campaign existing is enough; its strategies need not have
             * landed yet.
             *
             * This also required strategies()->exists(), and the two are
             * written 40-odd seconds apart — measured on customer 45: the
             * campaign row and auto_generated_at at 11:30:22, the strategy at
             * 11:31:03. Confirming inside that window sent the customer to the
             * wizard to build a campaign that had already been built for them,
             * and the duplicate is the one they would have kept.
             *
             * The guard was presumably there to avoid landing on an empty
             * page. It no longer can: Show seeds isPolling from
             * strategy_generation_started_at and narrates the wait, which is a
             * far better answer than a template chooser.
             */
            if ($autoCampaign) {
                // Pass the model, not its id: the route key is the uuid now, and an id
                // here produced a URL that resolved but leaked the count.
                return redirect()->route('campaigns.show', $autoCampaign)
                    ->with('success', 'Brand profile confirmed — here\'s the first campaign we built from it.');
            }

            if ($owner->isPaidSetupOnly()) {
                return redirect()->route('dashboard')
                    ->with('success', 'Brand profile confirmed — your campaign build is in progress.');
            }

            return redirect()->route('campaigns.create')
                ->with('success', 'Brand profile confirmed — let\'s build your first campaign.');
        }

        return back()->with('success', 'Brand guidelines verified!');
    }

    /**
     * Live status for the extraction the page is waiting on. Polled by the
     * frontend (useJobWatch) so "Extract" doesn't dispatch a queued job and
     * then go silent for the minutes it actually takes.
     */
    public function status(Request $request)
    {
        $user = $request->user();
        $customer = $user->customers()->find(session('active_customer_id'));

        if (! $customer) {
            return response()->json(['exists' => false, 'failed' => false]);
        }

        $guideline = $customer->brandGuideline()
            ->select(['id', 'customer_id', 'extraction_quality_score', 'extracted_at', 'updated_at'])
            ->first();

        // A scan-failure notification newer than the latest guideline means
        // the run the user is watching is the one that failed.
        $failure = $user->notifications()
            ->where('type', \App\Notifications\SiteScanFailed::class)
            ->where('data->customer_id', $customer->id)
            ->latest()
            ->first();

        $failed = $failure !== null
            && ($guideline === null || $failure->created_at->gt($guideline->updated_at));

        return response()->json([
            'exists' => $guideline !== null,
            'updated_at' => $guideline?->updated_at?->toIso8601String(),
            'quality_score' => $guideline?->extraction_quality_score,
            'failed' => $failed,
            'failure_reason' => $failed ? ($failure->data['reason'] ?? null) : null,
            // Lets the onboarding holding screen narrate the crawl phase
            // ("reading N pages…") before extraction begins.
            'pages' => \App\Models\KnowledgeBase::where('customer_id', $customer->id)->count(),
        ]);
    }

    /**
     * Trigger re-extraction of brand guidelines from knowledge base.
     */
    public function reExtract(Request $request)
    {
        $user = $request->user();
        $activeCustomerId = session('active_customer_id');

        if (! $activeCustomerId) {
            return redirect()->back()->with('error', 'No active customer selected.');
        }

        // Ensure user has access to this customer
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return redirect()->route('quick-start');
        }

        // force: the user explicitly asked; the freshness skip that guards
        // the onboarding chain's duplicate dispatches must not eat this run.
        \App\Jobs\ExtractBrandGuidelines::dispatch($customer, force: true);

        Log::info('Brand guideline re-extraction triggered', [
            'user_id' => $user->id,
            'customer_id' => $customer->id,
        ]);

        return back()->with('success', 'Brand guideline extraction started! This may take a few minutes.');
    }

    /**
     * Export brand guidelines as PDF.
     */
    public function exportPdf(Request $request)
    {
        $user = $request->user();
        $activeCustomerId = session('active_customer_id');

        if (! $activeCustomerId) {
            return redirect()->route('dashboard')->with('error', 'No active customer selected.');
        }

        // Ensure user has access to this customer
        $customer = $this->getActiveCustomer($request);

        if (! $customer) {
            return redirect()->route('quick-start');
        }
        $brandGuideline = $customer->brandGuideline;

        if (! $brandGuideline) {
            return redirect()->route('brand-guidelines.index')->with('error', 'No brand guidelines available to export.');
        }

        // Generate HTML view
        $html = view('brand-guidelines-pdf', [
            'brandGuideline' => $brandGuideline,
            'customer' => $customer,
        ])->render();

        // Generate filename
        $filename = str_replace(' ', '-', strtolower($customer->name)).'-brand-guidelines-'.date('Y-m-d').'.pdf';

        try {
            // Generate PDF using Browsershot
            $pdf = Browsershot::html($html)
                ->setNodeBinary(config('browsershot.node_binary_path'))
                ->addChromiumArguments(config('browsershot.chrome_args', []))
                ->setOption('landscape', false)
                ->margins(10, 10, 10, 10)
                ->format('A4')
                ->showBackground()
                ->pdf();

            Log::info('Brand guidelines PDF exported', [
                'user_id' => $user->id,
                'customer_id' => $customer->id,
                'brand_guideline_id' => $brandGuideline->id,
            ]);

            return response($pdf, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
        } catch (\Throwable $e) {
            report($e);
            Log::error('Failed to generate brand guidelines PDF', [
                'error' => $e->getMessage(),
                'customer_id' => $customer->id,
            ]);

            return redirect()->route('brand-guidelines.index')->with('error', 'Failed to generate PDF. Please try again.');
        }
    }
}
