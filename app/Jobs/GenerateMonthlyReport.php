<?php

namespace App\Jobs;

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
            $pdfContent = $pdfPath ? $pdfService->getPdfContent($customer, $report) : null;

            // Store report metadata for the Reports page
            $this->storeReportRecord($customer, $report, $pdfPath);

            // Email to all users
            foreach ($customer->users as $user) {
                if ($user->email) {
                    Mail::to($user->email)->queue(
                        new MonthlyExecutiveReport($user, $report, $pdfContent)
                    );
                }
            }

            Log::info("Monthly report generated for customer {$customer->id}", [
                'campaigns' => $report['summary']['total_campaigns'],
                'total_spend' => $report['summary']['total_cost'],
                'pdf_generated' => !empty($pdfPath),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to generate monthly report for customer {$this->customerId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Store report metadata in cache for the Reports listing page.
     */
    protected function storeReportRecord(Customer $customer, array $report, ?string $pdfPath): void
    {
        $key = "report_history:{$customer->id}";
        $history = Cache::get($key, []);

        array_unshift($history, [
            'period' => $report['period']['type'],
            'start' => $report['period']['start'],
            'end' => $report['period']['end'],
            'generated_at' => $report['generated_at'],
            'pdf_path' => $pdfPath,
            'summary' => [
                'total_cost' => $report['summary']['total_cost'],
                'total_clicks' => $report['summary']['total_clicks'],
                'total_conversions' => $report['summary']['total_conversions'],
                'blended_cpa' => $report['summary']['blended_cpa'],
            ],
        ]);

        // Keep last 24 reports
        $history = array_slice($history, 0, 24);
        Cache::put($key, $history, now()->addDays(365));
    }
}
