<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\Agents\AudienceIntelligenceAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunAudienceIntelligence implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \App\Jobs\Concerns\RecordsAgentRun;

    public $tries = 2;
    public $timeout = 600;

    public function handle(AudienceIntelligenceAgent $audienceAgent): void
    {
        Log::info("RunAudienceIntelligence: Starting agent execution");
        $runStart = $this->startRun();

        $customers = Customer::whereHas('campaigns', function ($q) {
            $q->withDeployedPlatforms();
        })->get();

        $processed = 0;
        $errors = 0;

        foreach ($customers as $customer) {
            try {
                Log::info("RunAudienceIntelligence: Analyzing customer {$customer->id}");
                $audienceAgent->analyzeAudiencePerformance($customer);
                $audienceAgent->refreshFacebookAudiences($customer);
                $processed++;
            } catch (\Exception $e) {
                $errors++;
                Log::error("RunAudienceIntelligence error on customer {$customer->id}: " . $e->getMessage());
            }
        }

        Log::info("RunAudienceIntelligence: Completed");

        $this->finishRun($runStart, actions: $processed, errors: $errors, scope: $customers->count() . ' customers');
    }

    public function failed(\Throwable $e): void
    {
        Log::error(class_basename($this) . ' failed: ' . $e->getMessage());
        $this->recordRunFailure($e);
    }
}
