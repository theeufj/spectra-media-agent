<?php

namespace App\Http\Controllers;

use App\Models\AgentActivity;
use App\Services\CreativeQuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        // If user has no customers yet, redirect to quick start onboarding
        if (!session('active_customer_id') || !$user->customers()->where('customers.id', session('active_customer_id'))->exists()) {
            $firstCustomer = $user->customers()->first();
            if ($firstCustomer) {
                session(['active_customer_id' => $firstCustomer->id]);
            } else {
                return redirect()->route('quick-start');
            }
        }

        $activeCustomer = $user->customers()->findOrFail(session('active_customer_id'));

        $campaigns = $activeCustomer->campaigns()
            ->with(['strategies'])
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Dashboard/Index', [
            'campaigns' => $campaigns,
            'defaultCampaign' => $campaigns->first(),
            'usageStats' => [
                'free_generations_used' => $user->free_generations_used,
                'cro_audits_used' => $activeCustomer->cro_audits_used,
                'subscription_status' => $user->subscribed('default') ? 'active' : 'inactive',
            ],
            'creativeUsage' => app(CreativeQuotaService::class)->getUsageSummary($user),
            'pendingTasks' => $this->getPendingTasks($campaigns),
            'healthAlerts' => $this->getHealthAlerts($campaigns),
            'agentActivities' => AgentActivity::where('customer_id', $activeCustomer->id)
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get(),
        ]);
    }

    /**
     * Compute pending tasks that need user attention.
     */
    private function getPendingTasks($campaigns): array
    {
        $tasks = [];

        foreach ($campaigns as $campaign) {
            // Strategies awaiting sign-off
            $unsignedStrategies = $campaign->strategies
                ->whereNull('signed_off_at')
                ->where('status', 'pending_approval');

            foreach ($unsignedStrategies as $strategy) {
                $tasks[] = [
                    'id' => "sign-off-{$strategy->id}",
                    'type' => 'sign-off',
                    'title' => "Sign off {$strategy->campaign_type} strategy",
                    'description' => "Review and approve the {$strategy->platform} {$strategy->campaign_type} strategy",
                    'campaign_name' => $campaign->name,
                    'priority' => 'high',
                    'href' => "/campaigns/{$campaign->id}/strategies",
                ];
            }

            // Strategies with collateral ready for review (deployed but not signed off)
            $readyStrategies = $campaign->strategies
                ->whereNotNull('execution_result')
                ->whereNull('signed_off_at');

            foreach ($readyStrategies as $strategy) {
                if ($strategy->status !== 'pending_approval') {
                    $tasks[] = [
                        'id' => "review-collateral-{$strategy->id}",
                        'type' => 'review-collateral',
                        'title' => "Review {$strategy->campaign_type} collateral",
                        'description' => "Ad copy and creative assets are ready for review",
                        'campaign_name' => $campaign->name,
                        'priority' => 'medium',
                        'href' => "/campaigns/{$campaign->id}/{$strategy->id}/collateral",
                    ];
                }
            }

            // Campaigns ready to deploy (all strategies signed off, not yet deployed)
            $allSignedOff = $campaign->strategies->isNotEmpty()
                && $campaign->strategies->every(fn ($s) => $s->signed_off_at !== null);
            $noneDeployed = $campaign->strategies->every(fn ($s) => $s->deployment_status !== 'deployed');

            if ($allSignedOff && $noneDeployed && !$campaign->google_ads_campaign_id) {
                $tasks[] = [
                    'id' => "deploy-{$campaign->id}",
                    'type' => 'deploy',
                    'title' => "Deploy campaign",
                    'description' => "All strategies approved — ready to go live",
                    'campaign_name' => $campaign->name,
                    'priority' => 'high',
                    'href' => "/campaigns/{$campaign->id}/strategies",
                ];
            }
        }

        return $tasks;
    }

    /**
     * Compute health alerts for active campaigns.
     */
    private function getHealthAlerts($campaigns): array
    {
        $alerts = [];

        foreach ($campaigns as $campaign) {
            // Campaign with policy violations or disapproved status
            if ($campaign->primary_status === 'REMOVED' || $campaign->primary_status === 'PAUSED') {
                $reasons = is_array($campaign->primary_status_reasons)
                    ? implode(', ', $campaign->primary_status_reasons)
                    : '';
                $alerts[] = [
                    'id' => "status-{$campaign->id}",
                    'severity' => 'critical',
                    'title' => "Campaign {$campaign->primary_status}",
                    'message' => '"' . $campaign->name . '" is ' . $campaign->primary_status . '. ' . $reasons,
                    'campaign_name' => $campaign->name,
                ];
            }

            // Strategy deployment failures
            foreach ($campaign->strategies as $strategy) {
                if ($strategy->deployment_status === 'failed') {
                    $alerts[] = [
                        'id' => "deploy-fail-{$strategy->id}",
                        'severity' => 'critical',
                        'title' => 'Deployment failed',
                        'message' => '"' . $campaign->name . '" ' . $strategy->campaign_type . ' deployment failed: ' . ($strategy->deployment_error ?: 'Unknown error'),
                        'campaign_name' => $campaign->name,
                    ];
                }
            }

            // Budget exhaustion warning (end date approaching)
            if ($campaign->end_date && $campaign->end_date->diffInDays(now()) <= 3 && $campaign->end_date->isFuture()) {
                $alerts[] = [
                    'id' => "budget-{$campaign->id}",
                    'severity' => 'warning',
                    'title' => 'Campaign ending soon',
                    'message' => '"' . $campaign->name . '" ends in ' . $campaign->end_date->diffInDays(now()) . ' days',
                    'campaign_name' => $campaign->name,
                ];
            }

            // Strategies generating for too long (stuck)
            if ($campaign->isGeneratingStrategies()
                && $campaign->strategy_generation_started_at
                && $campaign->strategy_generation_started_at->diffInMinutes(now()) > 15) {
                $alerts[] = [
                    'id' => "stuck-{$campaign->id}",
                    'severity' => 'warning',
                    'title' => 'Strategy generation may be stuck',
                    'message' => '"' . $campaign->name . '" has been generating strategies for over 15 minutes',
                    'campaign_name' => $campaign->name,
                ];
            }
        }

        return $alerts;
    }
}
