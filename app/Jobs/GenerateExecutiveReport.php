<?php

namespace App\Jobs;

use App\Mail\WeeklyExecutiveReport;
use App\Models\Customer;
use App\Services\Reporting\ExecutiveReportService;
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
}
