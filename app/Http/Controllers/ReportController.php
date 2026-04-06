<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateExecutiveReport;
use App\Jobs\GenerateMonthlyReport;
use App\Models\Customer;
use App\Services\Reporting\ExecutiveReportService;
use App\Services\Reporting\ReportPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ReportController extends Controller
{
    /**
     * Reports listing page — shows report history with download links.
     */
    public function index(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) {
            return redirect()->route('dashboard');
        }

        $history = Cache::get("report_history:{$customer->id}", []);

        return Inertia::render('Reports/Index', [
            'reports' => $history,
            'customer' => $customer->only('id', 'name', 'report_branding'),
            'canWhiteLabel' => $this->canWhiteLabel($request),
        ]);
    }

    /**
     * Generate an on-demand report.
     */
    public function generate(Request $request)
    {
        $request->validate([
            'period' => 'required|in:weekly,monthly',
        ]);

        $customer = $this->getActiveCustomer($request);
        if (!$customer) {
            return back()->with('flash', ['type' => 'error', 'message' => 'No active customer selected.']);
        }

        $period = $request->input('period');

        if ($period === 'monthly') {
            GenerateMonthlyReport::dispatch($customer->id);
        } else {
            GenerateExecutiveReport::dispatch($customer->id, 'weekly');
        }

        return back()->with('flash', [
            'type' => 'success',
            'message' => ucfirst($period) . ' report is being generated. You\'ll receive it by email shortly.',
        ]);
    }

    /**
     * Download a report PDF.
     */
    public function download(Request $request, string $period, string $date)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) {
            abort(403);
        }

        $filename = "reports/{$customer->id}/{$period}_{$date}.pdf";

        if (!Storage::disk('local')->exists($filename)) {
            // Try to regenerate from cached data
            $cacheKey = "executive_report:{$customer->id}:{$period}";
            $report = Cache::get($cacheKey);

            if ($report) {
                $pdfService = app(ReportPdfService::class);
                $pdfService->generate($customer, $report);
            }

            if (!Storage::disk('local')->exists($filename)) {
                return back()->with('flash', ['type' => 'error', 'message' => 'Report not found. Please generate a new one.']);
            }
        }

        $customerName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $customer->name);

        return response()->download(
            Storage::disk('local')->path($filename),
            "{$period}_report_{$customerName}_{$date}.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }

    /**
     * Report branding settings page.
     */
    public function settings(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Reports/Settings', [
            'customer' => $customer->only('id', 'name', 'report_branding'),
            'canWhiteLabel' => $this->canWhiteLabel($request),
        ]);
    }

    /**
     * Update report branding settings (Agency tier only).
     */
    public function updateBranding(Request $request)
    {
        if (!$this->canWhiteLabel($request)) {
            return back()->with('flash', ['type' => 'error', 'message' => 'White-label reports require an Agency plan.']);
        }

        $customer = $this->getActiveCustomer($request);
        if (!$customer) {
            return back()->with('flash', ['type' => 'error', 'message' => 'No active customer selected.']);
        }

        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'company_name' => 'nullable|string|max:100',
            'logo_url' => 'nullable|url|max:500',
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $customer->update([
            'report_branding' => [
                'enabled' => $validated['enabled'],
                'company_name' => $validated['company_name'] ?? null,
                'logo_url' => $validated['logo_url'] ?? null,
                'primary_color' => $validated['primary_color'] ?? null,
                'primary_dark' => !empty($validated['primary_color'])
                    ? $this->darkenColor($validated['primary_color'], 30) : null,
                'primary_darkest' => !empty($validated['primary_color'])
                    ? $this->darkenColor($validated['primary_color'], 60) : null,
            ],
        ]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Report branding updated.']);
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

    protected function canWhiteLabel(Request $request): bool
    {
        $user = $request->user();
        $plan = $user->assignedPlan;

        if ($plan && strtolower($plan->slug ?? $plan->name ?? '') === 'agency') {
            return true;
        }

        if ($user->subscribed('default')) {
            $subscription = $user->subscription('default');
            if ($subscription && $subscription->stripe_price) {
                $plan = \App\Models\Plan::where('stripe_price_id', $subscription->stripe_price)->first();
                return $plan && strtolower($plan->slug ?? '') === 'agency';
            }
        }

        return false;
    }

    protected function darkenColor(string $hex, int $percent): string
    {
        $hex = ltrim($hex, '#');
        $r = max(0, hexdec(substr($hex, 0, 2)) - (int)(hexdec(substr($hex, 0, 2)) * $percent / 100));
        $g = max(0, hexdec(substr($hex, 2, 2)) - (int)(hexdec(substr($hex, 2, 2)) * $percent / 100));
        $b = max(0, hexdec(substr($hex, 4, 2)) - (int)(hexdec(substr($hex, 4, 2)) * $percent / 100));

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
