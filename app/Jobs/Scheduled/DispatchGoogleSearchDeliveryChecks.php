<?php

namespace App\Jobs\Scheduled;

use App\Jobs\CheckGoogleSearchDelivery;
use App\Jobs\Concerns\RecordsAgentRun;
use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchGoogleSearchDeliveryChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsAgentRun, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function handle(): void
    {
        $started = $this->startRun();
        $dispatched = $errors = 0;
        try {
            Campaign::withoutCustomerScope()->whereNotNull('google_ads_campaign_id')->whereHas('customer')
                ->where(fn ($q) => $q->where('status', 'active')->orWhereNotNull('search_delivery_state'))
                ->select('campaigns.id')->chunkById(100, function ($campaigns) use (&$dispatched, &$errors) {
                    foreach ($campaigns as $campaign) {
                        try {
                            CheckGoogleSearchDelivery::dispatch((int) $campaign->getKey());
                            $dispatched++;
                        } catch (\Throwable $e) {
                            report($e);
                            $errors++;
                            Log::error('Search delivery check could not be queued', ['campaign_id' => $campaign->getKey(), 'error' => $e->getMessage()]);
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
