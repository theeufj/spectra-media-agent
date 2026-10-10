<?php

namespace App\Jobs\Scheduled;

use App\Jobs\CheckGoogleCampaignSpendSafety;
use App\Jobs\Concerns\RecordsAgentRun;
use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Spend protection is independent of subscriptions, Pennant and ad-group count. */
class DispatchCampaignSpendSafetyChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsAgentRun, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('spend-safety');
    }

    public function handle(): void
    {
        $started = $this->startRun();
        $dispatched = $errors = 0;
        try {
            Campaign::withoutCustomerScope()->whereNotNull('google_ads_campaign_id')
                ->whereHas('customer')
                ->where(fn ($query) => $query->where('status', 'active')
                    ->orWhereIn('primary_status', Campaign::SERVING_PRIMARY_STATUSES)
                    ->orWhereNotNull('spend_safety_hold')->orWhereNotNull('spend_guardrails'))
                ->select('campaigns.id')->chunkById(100, function ($campaigns) use (&$dispatched, &$errors) {
                    foreach ($campaigns as $campaign) {
                        try {
                            CheckGoogleCampaignSpendSafety::dispatch((int) $campaign->getKey());
                            $dispatched++;
                        } catch (\Throwable $e) {
                            report($e);
                            $errors++;
                            Log::error('Spend safety dispatch failed', ['campaign_id' => $campaign->getKey(), 'error' => $e->getMessage()]);
                        }
                    }
                });
        } finally {
            $this->finishRun($started, actions: $dispatched, errors: $errors, scope: "{$dispatched} campaigns queued");
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordRunFailure($exception);
    }
}
