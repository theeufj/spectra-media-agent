<?php

namespace App\Services\Testing;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\BudgetIntelligenceAgent;
use App\Services\Agents\CampaignAlertService;
use App\Services\Agents\CampaignOptimizationAgent;
use App\Services\Agents\CreativeIntelligenceAgent;
use App\Services\Agents\HealthCheckAgent;
use App\Services\Agents\SearchTermMiningAgent;
use App\Services\Agents\SelfHealingAgent;
use Illuminate\Support\Facades\Log;

class SandboxAgentRunner
{
    protected array $results = [];

    /**
     * Run all compatible agents against sandbox data and collect results.
     */
    public function runAll(Customer $customer): array
    {
        $this->results = [];
        $campaigns = $customer->campaigns()->get();

        // 1. Customer-level: Health Check
        $this->runHealthCheck($customer);

        // 2. Campaign-level agents
        foreach ($campaigns as $campaign) {
            $this->runAlerts($campaign, $customer);
            $this->runOptimization($campaign, $customer);
            $this->runBudgetIntelligence($campaign, $customer);
            $this->runSearchTermMining($campaign, $customer);
            $this->runCreativeIntelligence($campaign, $customer);
            $this->runSelfHealing($campaign, $customer);
        }

        return $this->results;
    }

    protected function runHealthCheck(Customer $customer): void
    {
        $agentType = 'HealthCheckAgent';

        try {
            $agent = app(HealthCheckAgent::class);
            $result = $agent->checkCustomerHealth($customer);

            $this->results[$agentType] = [
                'status' => 'completed',
                'data' => $result,
            ];

            AgentActivity::record(
                $agentType,
                'sandbox_health_check',
                'Sandbox: Overall health — ' . ($result['overall_health'] ?? 'unknown'),
                $customer->id,
                null,
                $result,
                'completed'
            );
        } catch (\Throwable $e) {
            $this->recordFailure($agentType, $customer->id, null, $e);
        }
    }

    protected function runAlerts(Campaign $campaign, Customer $customer): void
    {
        $agentType = 'CampaignAlertService';

        try {
            $agent = new CampaignAlertService();
            $result = $agent->checkAlerts($campaign);

            $key = "{$agentType}_{$campaign->id}";
            $this->results[$key] = [
                'status' => 'completed',
                'campaign' => $campaign->name,
                'data' => $result,
            ];

            AgentActivity::record(
                $agentType,
                'sandbox_alert_check',
                'Sandbox: Found ' . count($result) . " alerts for {$campaign->name}",
                $customer->id,
                $campaign->id,
                $result,
                'completed'
            );
        } catch (\Throwable $e) {
            $this->recordFailure($agentType, $customer->id, $campaign->id, $e);
        }
    }

    protected function runOptimization(Campaign $campaign, Customer $customer): void
    {
        $agentType = 'CampaignOptimizationAgent';

        try {
            $agent = app(CampaignOptimizationAgent::class);
            $result = $agent->analyze($campaign);

            $key = "{$agentType}_{$campaign->id}";
            $this->results[$key] = [
                'status' => $result ? 'completed' : 'no_data',
                'campaign' => $campaign->name,
                'data' => $result,
            ];

            AgentActivity::record(
                $agentType,
                'sandbox_optimization',
                'Sandbox: Optimization analysis for ' . $campaign->name,
                $customer->id,
                $campaign->id,
                $result ?? ['message' => 'Insufficient data for analysis'],
                'completed'
            );
        } catch (\Throwable $e) {
            $this->recordFailure($agentType, $customer->id, $campaign->id, $e);
        }
    }

    protected function runBudgetIntelligence(Campaign $campaign, Customer $customer): void
    {
        $agentType = 'BudgetIntelligenceAgent';

        try {
            $agent = new BudgetIntelligenceAgent();
            $result = $agent->optimize($campaign);

            $key = "{$agentType}_{$campaign->id}";
            $this->results[$key] = [
                'status' => 'completed',
                'campaign' => $campaign->name,
                'data' => $result,
            ];

            AgentActivity::record(
                $agentType,
                'sandbox_budget_optimization',
                'Sandbox: Budget multiplier — ' . ($result['multiplier_applied'] ?? 'none') . " for {$campaign->name}",
                $customer->id,
                $campaign->id,
                $result,
                'completed'
            );
        } catch (\Throwable $e) {
            $this->recordFailure($agentType, $customer->id, $campaign->id, $e);
        }
    }

    protected function runSearchTermMining(Campaign $campaign, Customer $customer): void
    {
        $agentType = 'SearchTermMiningAgent';

        try {
            $agent = new SearchTermMiningAgent();
            $result = $agent->mine($campaign);

            $key = "{$agentType}_{$campaign->id}";
            $this->results[$key] = [
                'status' => 'completed',
                'campaign' => $campaign->name,
                'data' => $result,
            ];

            AgentActivity::record(
                $agentType,
                'sandbox_search_mining',
                "Sandbox: Mined {$campaign->name} — {$result['terms_analyzed']} terms analyzed",
                $customer->id,
                $campaign->id,
                $result,
                'completed'
            );
        } catch (\Throwable $e) {
            $this->recordFailure($agentType, $customer->id, $campaign->id, $e);
        }
    }

    protected function runCreativeIntelligence(Campaign $campaign, Customer $customer): void
    {
        $agentType = 'CreativeIntelligenceAgent';

        try {
            $agent = app(CreativeIntelligenceAgent::class);
            $result = $agent->analyze($campaign);

            $key = "{$agentType}_{$campaign->id}";
            $this->results[$key] = [
                'status' => 'completed',
                'campaign' => $campaign->name,
                'data' => $result,
            ];

            AgentActivity::record(
                $agentType,
                'sandbox_creative_analysis',
                'Sandbox: Creative analysis for ' . $campaign->name,
                $customer->id,
                $campaign->id,
                $result,
                'completed'
            );
        } catch (\Throwable $e) {
            $this->recordFailure($agentType, $customer->id, $campaign->id, $e);
        }
    }

    protected function runSelfHealing(Campaign $campaign, Customer $customer): void
    {
        $agentType = 'SelfHealingAgent';

        try {
            $agent = app(SelfHealingAgent::class);
            $result = $agent->heal($campaign);

            $key = "{$agentType}_{$campaign->id}";
            $this->results[$key] = [
                'status' => 'completed',
                'campaign' => $campaign->name,
                'data' => $result,
            ];

            AgentActivity::record(
                $agentType,
                'sandbox_self_healing',
                'Sandbox: Self-healing for ' . $campaign->name,
                $customer->id,
                $campaign->id,
                $result,
                'completed'
            );
        } catch (\Throwable $e) {
            $this->recordFailure($agentType, $customer->id, $campaign->id, $e);
        }
    }

    protected function recordFailure(string $agentType, int $customerId, ?int $campaignId, \Throwable $e): void
    {
        $key = $campaignId ? "{$agentType}_{$campaignId}" : $agentType;

        $this->results[$key] = [
            'status' => 'error',
            'error' => $e->getMessage(),
        ];

        Log::warning("Sandbox agent {$agentType} failed", [
            'customer_id' => $customerId,
            'campaign_id' => $campaignId,
            'error' => $e->getMessage(),
        ]);

        AgentActivity::record(
            $agentType,
            'sandbox_error',
            "Sandbox: {$agentType} encountered an error — " . $e->getMessage(),
            $customerId,
            $campaignId,
            ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()],
            'failed'
        );
    }
}
