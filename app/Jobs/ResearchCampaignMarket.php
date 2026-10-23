<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Campaigns\CampaignMarketResearchWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ResearchCampaignMarket implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Campaign $campaign, public string $requestId) {}

    public function handle(CampaignMarketResearchWorkflow $workflow): void
    {
        $workflow->run($this->campaign, $this->requestId);
    }

    public function failed(?\Throwable $exception): void
    {
        if ($campaign = $this->campaign->fresh()) {
            app(CampaignMarketResearchWorkflow::class)->failed($campaign, $this->requestId);
        }
    }
}
