<?php

namespace App\Jobs;

use App\Jobs\Concerns\RecordsAgentRun;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\Campaigns\GoogleCampaignSpendSafety;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckGoogleCampaignSpendSafety implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsAgentRun, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $campaignId)
    {
        $this->onQueue('spend-safety');
    }

    public function handle(GoogleCampaignSpendSafety $safety): void
    {
        $started = $this->startRun();
        $campaign = Campaign::withoutCustomerScope()->find($this->campaignId);
        if (! $campaign instanceof Campaign) {
            $this->finishRun($started, scope: "Campaign {$this->campaignId}", note: 'Campaign no longer exists.');

            return;
        }
        try {
            $result = $safety->check($campaign);
            $this->finishRun($started, actions: $result['action'] === 'paused' ? 1 : 0,
                scope: "Campaign {$campaign->id}", details: $result);
        } catch (\Throwable $e) {
            report($e);
            Log::error('Campaign spend safety failed', ['campaign_id' => $campaign->id, 'error' => $e->getMessage()]);
            AgentActivity::record('spend_safety', 'campaign_spend_safety_failed',
                'Could not verify the spend safety check for "'.$campaign->name.'". Administrator attention is required.',
                $campaign->customer_id, $campaign->id, ['error' => $e->getMessage(), 'hold' => $campaign->fresh()->spend_safety_hold], 'failed');
            $this->finishRun($started, errors: 1, scope: "Campaign {$campaign->id}", note: $e->getMessage());
            throw $e; // queue retries a failed pause/read-back; it is not reported as success
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordRunFailure($exception);
    }
}
