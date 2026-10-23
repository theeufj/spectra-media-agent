<?php

namespace App\Services\Deployment;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\GoogleAdsExecutionAgent;
use Illuminate\Support\Facades\Log;

class GoogleAdsDeploymentStrategy implements DeploymentStrategy
{
    public function __construct(protected Customer $customer) {}

    public function deploy(Campaign $campaign, Strategy $strategy): bool
    {
        Log::info("Starting Google Ads deployment for Campaign ID: {$campaign->id}, Strategy ID: {$strategy->id}");

        try {
            if (in_array($strategy->campaign_type, ['search', 'display'], true)) {
                $destination = app(AdDestinationCheck::class)->check($campaign->landing_page_url);
                if ($destination['status'] !== 'reachable') {
                    $strategy->forceFill(['deployment_error' => $destination['message'], 'deployment_status' => 'failed',
                        'execution_result' => ['success' => false, 'metadata' => ['destination_check' => $destination]]])->save();

                    return false;
                }
            }

            // Every entry point uses the same allocation, paused staging and
            // initial bidding checks. Legacy Search/Display creation used to
            // bypass those checks and inherit Google's one-cent Manual CPC.
            return match ($strategy->campaign_type) {
                'search', 'display', 'video', 'shopping', 'demand_gen', 'local_services', 'performance_max' => $this->deployViaExecutionAgent($campaign, $strategy),
                'app' => throw new \RuntimeException('App campaigns are not yet supported. Please create App campaigns directly in Google Ads.'),
                default => throw new \RuntimeException("Unknown campaign type: {$strategy->campaign_type}"),
            };
        } catch (\Throwable $e) {
            report($e);
            Log::error("Google Ads deployment failed for Strategy ID {$strategy->id}: ".$e->getMessage());

            return false;
        }
    }

    private function deployViaExecutionAgent(Campaign $campaign, Strategy $strategy): bool
    {
        $agent = app(GoogleAdsExecutionAgent::class, ['customer' => $this->customer]);
        $result = $agent->execute(ExecutionContext::create($strategy, $campaign, $this->customer));
        $strategy->forceFill([
            'execution_result' => $result->toArray(),
            'deployment_error' => $result->success ? null : $result->errorMessage(),
            ...($result->success ? [] : ['deployment_status' => 'failed']),
        ])->save();

        return $result->success;
    }
}
