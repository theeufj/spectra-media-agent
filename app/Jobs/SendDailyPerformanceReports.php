<?php

namespace App\Jobs;

use App\Mail\DailyPerformanceReport;
use App\Models\Customer;
use App\Services\Reporting\YesterdayPerformanceSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendDailyPerformanceReports implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function handle(YesterdayPerformanceSummary $summaryService): void
    {
        $customers = Customer::whereHas('campaigns', function ($q) {
            $q->where('status', 'active');
        })->get();

        $sent = 0;

        foreach ($customers as $customer) {
            try {
                $summary = $summaryService->forCustomer($customer);

                // Skip if no data at all
                if ($summary['combined']['impressions'] === 0 && $summary['combined']['spend'] === 0) {
                    Log::info("Skipping daily report for customer {$customer->id} — no data for {$summary['date']}");
                    continue;
                }

                // Send to all users associated with this customer
                $users = $customer->users;

                foreach ($users as $user) {
                    if (!$user->email) {
                        continue;
                    }

                    Mail::to($user->email)->queue(
                        new DailyPerformanceReport($user, $summary)
                    );
                    $sent++;
                }

                Log::info("Daily performance report queued for customer {$customer->id}", [
                    'date' => $summary['date'],
                    'users_notified' => $users->count(),
                    'combined_spend' => $summary['combined']['spend'],
                ]);
            } catch (\Exception $e) {
                Log::error("Failed to send daily report for customer {$customer->id}: " . $e->getMessage());
            }
        }

        Log::info("Daily performance reports complete: {$sent} emails queued across {$customers->count()} customers");
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendDailyPerformanceReports failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
