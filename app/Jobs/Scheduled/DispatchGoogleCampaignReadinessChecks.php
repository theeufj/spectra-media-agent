<?php

namespace App\Jobs\Scheduled;

use App\Jobs\CheckGoogleCampaignReadiness;
use App\Jobs\Concerns\RecordsAgentRun;
use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Fan out observations without requiring a campaign to already be serving. */
class DispatchGoogleCampaignReadinessChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsAgentRun, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function handle(): void
    {
        $started = $this->startRun();
        $dispatched = $errors = 0;
        try {
            Campaign::query()->whereHas('customer')
                ->where(function ($query) {
                    $query->whereNotNull('google_ads_campaign_id')
                        ->orWhereHas('strategies', fn ($strategy) => $strategy->whereNotNull('google_ads_campaign_id'));
                })
                ->select('campaigns.id')
                ->chunkById(100, function ($campaigns) use (&$dispatched, &$errors) {
                    foreach ($campaigns as $campaign) {
                        try {
                            $this->dispatchCampaign($campaign->id);
                            $dispatched++;
                        } catch (\Throwable $e) {
                            report($e);
                            $errors++;
                            Log::error('Google readiness dispatch failed', ['campaign_id' => $campaign->id, 'error' => $e->getMessage()]);
                        }
                    }
                });
        } catch (\Throwable $e) {
            $errors++;
            throw $e;
        } finally {
            $this->finishRun($started, actions: $dispatched, errors: $errors, scope: $dispatched.' Google campaigns queued');
        }
    }

    protected function dispatchCampaign(int $campaignId): void
    {
        CheckGoogleCampaignReadiness::dispatch($campaignId);
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordRunFailure($exception);
    }
}
