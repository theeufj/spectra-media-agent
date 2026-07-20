<?php

namespace App\Jobs;

use App\Mail\DailyPerformanceReport;
use App\Models\Customer;
use App\Models\Recommendation;
use App\Services\Reporting\YesterdayPerformanceSummary;
use App\Services\GeminiService;
use App\Models\GoogleAdsPerformanceData;
use App\Models\FacebookAdsPerformanceData;
use Illuminate\Support\Carbon;
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

                // Skip if there was no real activity for the day. An all-zero report is
                // noise (and reads as broken next to a cached strategic recommendation
                // that cites cumulative campaign numbers). Compare numerically — the
                // metrics are ints/floats, so a strict `=== 0` on the float spend never
                // matched and empty reports were being sent. Checked before enrichment so
                // we don't burn Gemini calls building a rollup we'll throw away.
                $c = $summary['combined'];
                if ((float) ($c['impressions'] ?? 0) == 0
                    && (float) ($c['clicks'] ?? 0) == 0
                    && (float) ($c['spend'] ?? 0) == 0
                    && (float) ($c['conversions'] ?? 0) == 0) {
                    Log::info("Skipping daily report for customer {$customer->id} — no activity for {$summary['date']}");
                    continue;
                }

                // On Mondays, append a 7-day WoW rollup sentence per platform
                if ($isMonday) {
                    $summary['weekly_rollup'] = $this->buildWeeklyRollup($customer, $gemini);
                }

                // Report what WE did on the client's behalf — the optimisations applied
                // automatically — rather than handing them a to-do. This is the product:
                // an autonomous agent that acts and reports back.
                $summary['optimizations'] = $this->getOptimizationsMade($customer, $summary['date']);

                // Send to all users associated with this customer
                $users = $customer->users;

                foreach ($users as $user) {
                    if (!$user->email) {
                        continue;
                    }

                    // Respect unsubscribe preference set via email.unsubscribe route
                    $prefs = $user->notification_preferences ?? [];
                    if (isset($prefs['performance_reports']) && $prefs['performance_reports'] === false) {
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
     * Summarise the optimisations we actually applied on the client's behalf during the
     * reporting window (the overnight OptimizeCampaigns run), plus a count of any
     * recommendations still awaiting their approval.
     *
     * @return array{applied: array<int,array{label:string,rationale:?string,campaign:?string}>, pending: int}
     */
    private function getOptimizationsMade(Customer $customer, string $date): array
    {
        $campaigns = $customer->campaigns()->pluck('name', 'id');
        if ($campaigns->isEmpty()) {
            return ['applied' => [], 'pending' => 0];
        }

        // Changes applied since the start of the reporting day cover the overnight
        // optimisation run that the 08:00 report follows.
        $since = Carbon::parse($date)->startOfDay();

        $applied = Recommendation::whereIn('campaign_id', $campaigns->keys())
            ->where('status', 'applied')
            ->where('created_at', '>=', $since)
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Recommendation $r) => [
                'label'     => $this->optimizationLabel($r->type),
                'rationale' => $r->rationale,
                'campaign'  => $campaigns[$r->campaign_id] ?? null,
            ])
            ->all();

        $pending = Recommendation::whereIn('campaign_id', $campaigns->keys())
            ->where('status', 'pending')
            ->where('requires_approval', true)
            ->count();

        return ['applied' => $applied, 'pending' => $pending];
    }

    /**
     * Human-readable label for an applied recommendation type.
     */
    private function optimizationLabel(?string $type): string
    {
        return match ($type) {
            'BUDGET'           => 'Adjusted daily budget',
            'KEYWORDS'         => 'Updated keywords',
            'NEGATIVE_KEYWORDS', 'NEGATIVE_KEYWORD_ADDITION', 'SEARCH_TERM_REVIEW' => 'Added negative keywords',
            'BIDDING'       => 'Tuned bidding strategy',
            'TARGETING'     => 'Refined targeting',
            'AD_EXTENSIONS'    => 'Added ad extensions',
            'SCHEDULE'         => 'Optimised ad schedule',
            'AUDIENCE'         => 'Updated audiences',
            'NETWORK_SETTINGS' => 'Disabled out-of-network placements',
            default            => 'Optimisation applied',
        };
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
                    config('ai.models.default'),
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
