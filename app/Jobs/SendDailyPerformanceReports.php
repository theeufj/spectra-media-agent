<?php

namespace App\Jobs;

use App\Mail\DailyPerformanceReport;
use App\Models\Customer;
use App\Services\Reporting\YesterdayPerformanceSummary;
use App\Services\GeminiService;
use App\Models\GoogleAdsPerformanceData;
use App\Models\FacebookAdsPerformanceData;
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

    public function handle(YesterdayPerformanceSummary $summaryService, GeminiService $gemini): void
    {
        $isMonday = now()->dayOfWeek === 1;

        $customers = Customer::whereHas('campaigns', function ($q) {
            $q->where('status', 'active');
        })->get();

        $sent = 0;

        foreach ($customers as $customer) {
            try {
                $summary = $summaryService->forCustomer($customer);

                // On Mondays, append a 7-day WoW rollup sentence per platform
                if ($isMonday) {
                    $summary['weekly_rollup'] = $this->buildWeeklyRollup($customer, $gemini);
                }

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
     * Build a 7-day WoW rollup summary for Monday emails.
     * Returns per-platform trend data + a Gemini one-liner per platform.
     */
    private function buildWeeklyRollup(Customer $customer, GeminiService $gemini): array
    {
        $campaignIds  = $customer->campaigns()->pluck('id');
        $thisWeekStart = now()->subDays(7)->toDateString();
        $lastWeekStart = now()->subDays(14)->toDateString();
        $lastWeekEnd   = now()->subDays(7)->toDateString();

        $rollup = [];

        $platforms = [
            'google'   => GoogleAdsPerformanceData::class,
            'facebook' => FacebookAdsPerformanceData::class,
        ];

        foreach ($platforms as $name => $model) {
            $current = $model::whereIn('campaign_id', $campaignIds)->where('date', '>=', $thisWeekStart)->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions')->first();
            $prior   = $model::whereIn('campaign_id', $campaignIds)->whereBetween('date', [$lastWeekStart, $lastWeekEnd])->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(cost) as cost, SUM(conversions) as conversions')->first();

            if (!$current || ($current->impressions ?? 0) == 0) {
                continue;
            }

            $spend    = round($current->cost        ?? 0, 2);
            $convs    = round($current->conversions ?? 0, 1);
            $cpa      = $convs > 0 ? round($spend / $convs, 2) : null;
            $priorCpa = ($prior && ($prior->conversions ?? 0) > 0) ? round(($prior->cost ?? 0) / $prior->conversions, 2) : null;
            $trend    = ($cpa && $priorCpa) ? ($cpa < $priorCpa ? '↓' : '↑') : '→';

            $rollup[$name] = [
                'spend'       => $spend,
                'conversions' => $convs,
                'cpa'         => $cpa,
                'prior_cpa'   => $priorCpa,
                'trend'       => $trend,
                'summary'     => null,
            ];

            // One Gemini sentence per platform
            try {
                $priorCpaStr = $priorCpa ? "\${$priorCpa}" : 'N/A';
                $cpaStr      = $cpa      ? "\${$cpa}"      : 'N/A';
                $response = $gemini->generateContent(
                    'gemini-2.0-flash',
                    "Write one sentence (≤20 words) summarising this week's " . ucfirst($name) . " Ads performance for {$customer->name}: spend \${$spend}, {$convs} conversions, CPA {$cpaStr} vs last week {$priorCpaStr}.",
                    ['temperature' => 0.3, 'maxOutputTokens' => 60]
                );
                $rollup[$name]['summary'] = trim($response['text'] ?? '');
            } catch (\Exception $e) {
                Log::debug("SendDailyPerformanceReports: weekly rollup Gemini failed: " . $e->getMessage());
            }
        }

        return $rollup;
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
