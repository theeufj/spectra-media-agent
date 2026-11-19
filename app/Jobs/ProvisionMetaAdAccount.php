<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\DeploymentService;
use App\Services\FacebookAds\CreateFacebookAdsAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProvisionMetaAdAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $timeout = 150;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public Campaign $campaign, public string $requestId) {}

    public function handle(): void
    {
        $campaign = $this->campaign->fresh(['customer', 'strategies']);
        if (! $campaign?->customer || $campaign->hasPassedEndDate()
            || ($campaign->search_delivery_state['market_research']['request_id'] ?? null) !== $this->requestId
            || ! $campaign->strategies->contains(fn ($strategy) => DeploymentService::normalizePlatform($strategy->platform) === 'facebook')) {
            return;
        }

        app(CreateFacebookAdsAccount::class, ['customer' => $campaign->customer])->provision();
    }
}
