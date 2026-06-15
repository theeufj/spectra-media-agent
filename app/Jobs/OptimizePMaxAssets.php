<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Agents\PMaxAssetOptimizationAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class OptimizePMaxAssets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;

    protected int $campaignId;

    public function __construct(int $campaignId)
    {
        $this->campaignId = $campaignId;
    }

    public function handle(): void
    {
        $campaign = Campaign::with('customer')->findOrFail($this->campaignId);
        $customer = $campaign->customer;

        if (!$customer) {
            Log::warning("OptimizePMaxAssets: No customer found for campaign {$this->campaignId}");
            return;
        }

        Log::info("OptimizePMaxAssets: Running PMax asset optimisation for campaign {$this->campaignId}");

        $agent  = new PMaxAssetOptimizationAgent($customer);
        $result = $agent->run($campaign);

        Log::info("OptimizePMaxAssets: Completed for campaign {$this->campaignId}", [
            'low_detected'  => $result['low_detected'],
            'text_replaced' => $result['text_replaced'],
            'image_flagged' => $result['image_flagged'],
            'errors'        => $result['errors'],
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('OptimizePMaxAssets failed: ' . $exception->getMessage(), [
            'campaign_id' => $this->campaignId,
            'exception'   => $exception->getTraceAsString(),
        ]);
    }
}
