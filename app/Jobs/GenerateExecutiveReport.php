<?php

namespace App\Jobs;

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
            } catch (\Exception $e) {
                Log::warning("PDF generation failed for weekly report, continuing: " . $e->getMessage());
            }

            // Store report metadata for the Reports listing page
            $this->storeReportRecord($customer, $report, $pdfPath);

            // Email the report to all users associated with this customer
            foreach ($customer->users as $user) {
                if ($user->email) {
                    Mail::to($user->email)->queue(
                        new WeeklyExecutiveReport($user, $report)
                    );
                }
            }

            Log::info("Executive report generated for customer {$customer->id}", [
                'period' => $this->period,
                'campaigns' => $report['summary']['total_campaigns'],
                'total_spend' => $report['summary']['total_cost'],
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to generate executive report for customer {$this->customerId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

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

        $history = array_slice($history, 0, 24);
        Cache::put($key, $history, now()->addDays(365));
    }
}
