<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\GoogleAdsExecutionAgent;
use App\Services\Agents\FacebookAdsExecutionAgent;
use App\Services\Deployment\DeploymentStrategy;
use App\Services\Deployment\GoogleAdsDeploymentStrategy;
use App\Services\Deployment\FacebookAdsDeploymentStrategy;
use Illuminate\Support\Facades\Log;

class DeploymentService
{
    /**
     * Deploy a campaign strategy using either execution agents (new) or deployment strategies (legacy).
     * 
     * @param Campaign $campaign The campaign to deploy
     * @param Strategy $strategy The strategy to deploy
     * @param Customer $customer The customer/account owner
     * @param bool $useAgents Whether to use new execution agents (default: true)
     * @return array Result array with 'success' boolean and optional 'result' or 'error'
     */
    public static function deploy(
        Campaign $campaign, 
        Strategy $strategy, 
        Customer $customer,
        bool $useAgents = true
    ): array {
        // Check feature flag from environment
        $useAgentsFlag = config('app.use_execution_agents', true);
        $useAgents = $useAgents && $useAgentsFlag;
        
        Log::info("DeploymentService: Deploying strategy", [
            'campaign_id' => $campaign->id,
            'strategy_id' => $strategy->id,
            'platform' => $strategy->platform,
            'use_agents' => $useAgents
        ]);
        
        if ($useAgents) {
            return self::deployWithAgent($campaign, $strategy, $customer);
        } else {
            return self::deployWithStrategy($campaign, $strategy, $customer);
        }
    }
    
    /**
     * Deploy using new AI-powered execution agents.
     */
    protected static function deployWithAgent(
        Campaign $campaign, 
        Strategy $strategy, 
        Customer $customer
    ): array {
        try {
            $agent = self::getAgent($strategy->platform, $customer);
            
            if (!$agent) {
                Log::warning("No execution agent found for platform: {$strategy->platform}");
                return [
                    'success' => false,
                    'error' => "No execution agent available for platform: {$strategy->platform}"
                ];
            }
            
            // Create execution context
            $context = ExecutionContext::create($strategy, $campaign, $customer);
            
            // Execute with agent
            $result = $agent->execute($context);
            
            // Store execution results in strategy
            $strategy->execution_plan = $result->plan ? $result->plan->toArray() : null;
            $strategy->execution_result = [
                'success' => $result->success,
                'platform_ids' => $result->platformIds,
                'execution_time' => $result->executionTime,
                'errors' => $result->errors,
                'warnings' => $result->warnings,
                'executed_at' => now()->toIso8601String()
            ];
            $strategy->execution_time = $result->executionTime;
            $strategy->execution_errors = $result->errors;
            $strategy->save();
            
            if ($result->success) {
                Log::info("DeploymentService: Successfully deployed with agent", [
                    'campaign_id' => $campaign->id,
                    'strategy_id' => $strategy->id,
                    'platform' => $strategy->platform,
                    'execution_time' => $result->executionTime,
                    'platform_ids' => count($result->platformIds)
                ]);
                
                return [
                    'success' => true,
                    'result' => $result
                ];
            } else {
                Log::error("DeploymentService: Agent deployment failed", [
                    'campaign_id' => $campaign->id,
                    'strategy_id' => $strategy->id,
                    'platform' => $strategy->platform,
                    'errors' => $result->errors
                ]);
                
                $errorMessages = array_map(function($error) {
                    if (is_array($error)) {
                        return $error['message'] ?? json_encode($error);
                    }
                    if (is_object($error)) {
                        return method_exists($error, '__toString') ? (string)$error : get_class($error);
                    }
                    return (string)$error;
                }, $result->errors);
                
                return [
                    'success' => false,
                    'error' => implode(', ', $errorMessages),
                    'result' => $result
                ];
            }
            
        } catch (\Exception $e) {
            Log::error("DeploymentService: Exception during agent deployment: " . $e->getMessage(), [
                'campaign_id' => $campaign->id,
                'strategy_id' => $strategy->id,
                'platform' => $strategy->platform,
                'exception' => $e
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Deploy using legacy deployment strategies.
     */
    protected static function deployWithStrategy(
        Campaign $campaign, 
        Strategy $strategy, 
        Customer $customer
    ): array {
        try {
            $deploymentStrategy = self::getStrategy($strategy->platform, $customer);
            
            if (!$deploymentStrategy) {
                Log::warning("No deployment strategy found for platform: {$strategy->platform}");
                return [
                    'success' => false,
                    'error' => "No deployment strategy available for platform: {$strategy->platform}"
                ];
            }
            
            $success = $deploymentStrategy->deploy($campaign, $strategy);
            
            if ($success) {
                Log::info("DeploymentService: Successfully deployed with legacy strategy", [
                    'campaign_id' => $campaign->id,
                    'strategy_id' => $strategy->id,
                    'platform' => $strategy->platform
                ]);
                
                return ['success' => true];
            } else {
                Log::error("DeploymentService: Legacy strategy deployment failed", [
                    'campaign_id' => $campaign->id,
                    'strategy_id' => $strategy->id,
                    'platform' => $strategy->platform
                ]);
                
                return [
                    'success' => false,
                    'error' => "Deployment failed for {$strategy->platform}"
                ];
            }
            
        } catch (\Exception $e) {
            Log::error("DeploymentService: Exception during legacy deployment: " . $e->getMessage(), [
                'campaign_id' => $campaign->id,
                'strategy_id' => $strategy->id,
                'platform' => $strategy->platform,
                'exception' => $e
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get execution agent for platform.
     * 
     * @param string $platform Platform name
     * @param Customer $customer Customer/account owner
     * @return GoogleAdsExecutionAgent|FacebookAdsExecutionAgent|null
     */
    protected static function getAgent(string $platform, Customer $customer): mixed
    {
        return match ($platform) {
            'Google Ads (SEM)', 'Google', 'Google Ads' => new GoogleAdsExecutionAgent($customer, app(GeminiService::class)),
            'Facebook Ads', 'Facebook' => new FacebookAdsExecutionAgent($customer, app(GeminiService::class)),
            default => null
        };
    }
    
    /**
     * Factory method to get the correct deployment strategy for a given platform (legacy).
     *
     * @param string $platform The name of the platform (e.g., 'Google Ads (SEM)', 'Facebook Ads').
     * @param Customer $customer The customer object containing the necessary credentials.
     * @return DeploymentStrategy|null
     */
    protected static function getStrategy(string $platform, Customer $customer): ?DeploymentStrategy
    {
        return match ($platform) {
            'Google Ads (SEM)' => new GoogleAdsDeploymentStrategy($customer),
            'Facebook Ads' => new FacebookAdsDeploymentStrategy($customer),
            default => null
        };
    }
}
