<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\GoogleAds\PerformanceMaxServices\PerformanceMaxReplacementPending;
use App\Services\GoogleAds\PerformanceMaxServices\RefreshPerformanceMaxAssets;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

final class RetireReviewedPerformanceMaxImages implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public int $maxExceptions = 1;

    // Keep duplicate deliveries out until after the five-day review window.
    public int $uniqueFor = 6 * 86400;

    private int $deadline;

    public function __construct(private int $strategyId, private array $scope, private array $links)
    {
        $this->deadline = now()->addDays(5)->timestamp;
    }

    public function uniqueId(): string
    {
        return $this->strategyId.':'.hash('sha256', json_encode([$this->scope, $this->links], JSON_THROW_ON_ERROR));
    }

    public function retryUntil(): \DateTimeInterface
    {
        return Carbon::createFromTimestampUTC($this->deadline);
    }

    public function handle(): void
    {
        if (now()->timestamp >= $this->deadline) {
            throw new \RuntimeException('The Google replacement review window expired. Recheck the asset association before requesting retirement again.');
        }
        $strategy = Strategy::with('campaign.customer')->findOrFail($this->strategyId);
        $customer = $strategy->campaign?->customer;
        if (! $customer || $customer->id !== $this->scope['customer_id'] || $strategy->campaign_id !== $this->scope['campaign_id']) {
            throw new \RuntimeException('The owner of the queued Performance Max retirement changed.');
        }
        try {
            $result = app(RefreshPerformanceMaxAssets::class, ['customer' => $customer])->refresh(
                $strategy, apply: true, retireImageLinks: $this->links, retirementScope: $this->scope,
            );
        } catch (PerformanceMaxReplacementPending $exception) {
            if (now()->addMinutes(30)->timestamp >= $this->deadline) {
                throw new \RuntimeException('Google still has a replacement under review at the final scheduled check.', previous: $exception);
            }
            $this->release(1800);

            return;
        }
        if ($result['validate_only'] || ! $result['links_confirmed']) {
            throw new \RuntimeException('Google did not confirm removal of the requested Performance Max image associations.');
        }
        $this->activity('completed', 'Google confirmed retirement of the requested Performance Max image associations.');
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
        $this->activity('failed', 'Performance Max image retirement stopped before confirmation. Check the Google asset review and campaign holds.');
    }

    private function activity(string $status, string $description): void
    {
        if (! Customer::whereKey($this->scope['customer_id'])->exists()) {
            return; // The exception report survives deletion of the tenant's activity feed.
        }
        $campaignId = Campaign::whereKey($this->scope['campaign_id'])->where('customer_id', $this->scope['customer_id'])->value('id');
        $requestId = $this->uniqueId();
        AgentActivity::where('details->request_id', $requestId)->firstOrCreate([
            'agent_type' => 'google_ads', 'action' => 'retire_pmax_images', 'status' => $status,
            'customer_id' => $this->scope['customer_id'], 'campaign_id' => $campaignId,
        ], ['description' => $description, 'details' => ['request_id' => $requestId, 'strategy_id' => $this->strategyId,
            'campaign' => $this->scope['campaign'], 'asset_group' => $this->scope['group'],
            'fingerprint' => $this->scope['fingerprint'], 'associations' => $this->links]]);
    }
}
