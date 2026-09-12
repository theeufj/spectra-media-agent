<?php

namespace App\Jobs;

use App\Jobs\Concerns\RecordsReportHistory;
use App\Mail\WeeklyExecutiveReport;
use App\Models\Customer;
use App\Services\Reporting\ExecutiveReportService;
use App\Services\Reporting\ReportPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GenerateExecutiveReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use RecordsReportHistory;

    public int $tries = 2;

    public int $timeout = 300;

    protected int $customerId;

    protected string $period;

    public function __construct(int $customerId, string $period = 'weekly')
    {
        $this->customerId = $customerId;
        $this->period = $period;
    }

    public function handle(ExecutiveReportService $reportService): void
    {
        try {
            $customer = Customer::findOrFail($this->customerId);

            Log::info("Generating {$this->period} executive report for customer {$customer->id}");

            $report = $reportService->generate($customer, $this->period);

            // Cache the report for retrieval
            $cacheKey = "executive_report:{$customer->id}:{$this->period}";
            Cache::put($cacheKey, $report, now()->addDays($this->period === 'monthly' ? 35 : 10));

            // Generate PDF for report history
            $pdfPath = null;
            try {
                $pdfService = app(ReportPdfService::class);
                $pdfPath = $pdfService->generate($customer, $report);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('PDF generation failed for weekly report, continuing: '.$e->getMessage());
            }

            // Store report metadata for the Reports listing page
            $this->storeReportRecord($customer, $report, $pdfPath);

            // Skip sending if there's no meaningful data — zero spend and zero impressions
            // means the campaigns weren't running during this period.
            $hasData = ($report['summary']['total_cost'] ?? 0) > 0
                    || ($report['summary']['total_impressions'] ?? 0) > 0
                    || ($report['summary']['total_clicks'] ?? 0) > 0;

            if (! $hasData) {
                Log::info("Skipping {$this->period} executive report for customer {$customer->id} — no performance data in period");

                return;
            }

            // Email the report to all users associated with this customer
            foreach ($customer->users as $user) {
                if (! $user->email) {
                    continue;
                }
                $prefs = $user->notification_preferences ?? [];
                if (isset($prefs['performance_reports']) && $prefs['performance_reports'] === false) {
                    continue;
                }
                Mail::to($user->email)->queue(new WeeklyExecutiveReport($user, $report));
            }

            Log::info("Executive report generated for customer {$customer->id}", [
                'period' => $this->period,
                'campaigns' => $report['summary']['total_campaigns'],
                'total_spend' => $report['summary']['total_cost'],
            ]);
        } catch (\Throwable $e) {
            Log::error("Failed to generate executive report for customer {$this->customerId}: ".$e->getMessage(), [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateExecutiveReport failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
