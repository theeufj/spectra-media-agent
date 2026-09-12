<?php

namespace App\Jobs;

use App\Jobs\Concerns\RecordsReportHistory;
use App\Mail\MonthlyExecutiveReport;
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

class GenerateMonthlyReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use RecordsReportHistory;

    public int $tries = 2;

    public int $timeout = 600;

    protected int $customerId;

    public function __construct(int $customerId)
    {
        $this->customerId = $customerId;
    }

    public function handle(ExecutiveReportService $reportService, ReportPdfService $pdfService): void
    {
        try {
            $customer = Customer::findOrFail($this->customerId);

            Log::info("Generating monthly report for customer {$customer->id}");

            // Generate report data
            $report = $reportService->generate($customer, 'monthly');

            // Cache the report for retrieval via the Reports page
            $cacheKey = "executive_report:{$customer->id}:monthly";
            Cache::put($cacheKey, $report, now()->addDays(35));

            // Generate PDF
            $pdfPath = $pdfService->generate($customer, $report);

            // Store report metadata for the Reports page
            $this->storeReportRecord($customer, $report, $pdfPath);

            // Email to all users
            foreach ($customer->users as $user) {
                if (! $user->email) {
                    continue;
                }
                $prefs = $user->notification_preferences ?? [];
                if (isset($prefs['performance_reports']) && $prefs['performance_reports'] === false) {
                    continue;
                }
                Mail::to($user->email)->queue(new MonthlyExecutiveReport($user, $report, $pdfPath));
            }

            Log::info("Monthly report generated for customer {$customer->id}", [
                'campaigns' => $report['summary']['total_campaigns'],
                'total_spend' => $report['summary']['total_cost'],
                'pdf_generated' => ! empty($pdfPath),
            ]);
        } catch (\Throwable $e) {
            Log::error("Failed to generate monthly report for customer {$this->customerId}: ".$e->getMessage(), [
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
        Log::error('GenerateMonthlyReport failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
