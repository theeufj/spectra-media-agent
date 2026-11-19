<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\EnabledPlatform;
use App\Services\Agents\CampaignDiagnosticsAgent;
use App\Services\Agents\CampaignRemediationAgent;
use App\Services\FacebookAds\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Daily strategic campaign diagnosis and autonomous remediation.
 *
 * Runs every morning at 06:00 across all active deployed campaigns.
 * Detects issues the reactive 4-hour checks miss:
 *   - Conversion starvation (spend without conversions)
 *   - PMax structural gaps (no audience signals, wrong landing pages)
 *   - Display-only traffic (no search intent)
 *   - Missing conversion tracking labels
 *
 * Auto-fixes what it can (creative refresh, conversion provisioning).
 * Alerts the customer with actionable instructions for the rest.
 */
class RunStrategicDiagnosis implements ShouldQueue
{
    use \App\Jobs\Concerns\RecordsAgentRun, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900; // 15 minutes

    public function handle(
        CampaignDiagnosticsAgent $diagnosticsAgent,
        CampaignRemediationAgent $remediationAgent
    ): void {
        Log::info('RunStrategicDiagnosis: Starting daily diagnosis pass');
        $runStart = $this->startRun();

        $campaigns = Campaign::with('customer')
            ->withDeployedPlatforms()
            ->where(fn ($query) => $query->whereNotIn('status', ['paused', 'draft', 'ended'])
                ->orWhere(fn ($paused) => $paused->where('status', 'paused')
                    ->whereNotNull('facebook_ads_campaign_id')
                    ->whereHas('strategies', fn ($search) => $search->where('campaign_type', 'search')
                        ->where('deployment_status', 'paused')->whereColumn('strategies.google_ads_campaign_id', 'campaigns.google_ads_campaign_id')
                        ->whereNotNull('execution_result->metadata->owner_paused_at'))
                    ->whereHas('strategies', fn ($meta) => $meta->where('deployment_status', 'active')
                        ->whereColumn('strategies.facebook_campaign_id', 'campaigns.facebook_ads_campaign_id'))))
            ->get();

        $totalFindings = 0;
        $totalFixed = 0;
        $totalAlerts = 0;
        $errors = 0;

        foreach ($campaigns as $campaign) {
            $lock = Cache::lock("strategic_diagnosis:campaign:{$campaign->id}", 3600);
            if (! $lock->get()) {
                continue;
            }

            try {
                if ($campaign->status === CampaignStatus::Paused) {
                    if (! $this->hasLiveMetaPlacement($campaign)) {
                        continue;
                    }
                    $findings = $diagnosticsAgent->diagnoseMeta($campaign);
                    $totalFindings += count($findings);
                    AgentActivity::record('strategic_diagnosis', 'diagnosed',
                        'Checked the active Meta placement on "'.$campaign->name.'".',
                        $campaign->customer_id, $campaign->id, ['platform' => 'meta', 'mode' => 'read_only',
                            'findings' => $findings, 'actions_taken' => [], 'alerts_sent' => []]);

                    continue;
                }
                $findings = $diagnosticsAgent->diagnose($campaign);

                if (empty($findings)) {
                    continue;
                }

                $totalFindings += count($findings);

                Log::info('RunStrategicDiagnosis: Findings for campaign '.$campaign->id, [
                    'campaign' => $campaign->name,
                    'count' => count($findings),
                    'types' => array_column($findings, 'type'),
                ]);

                $result = $remediationAgent->remediate($campaign, $findings);

                $totalFixed += count($result['actions_taken'] ?? []);
                $totalAlerts += count($result['alerts_sent'] ?? []);
                $errors += count($result['errors'] ?? []);

                if (! empty($findings)) {
                    AgentActivity::record(
                        'strategic_diagnosis',
                        'diagnosed',
                        count($findings).' strategic issue(s) found in "'.$campaign->name.'"',
                        $campaign->customer_id,
                        $campaign->id,
                        [
                            'findings' => array_map(fn ($f) => ['type' => $f['type'], 'severity' => $f['severity']], $findings),
                            'actions_taken' => $result['actions_taken'] ?? [],
                            'alerts_sent' => $result['alerts_sent'] ?? [],
                        ]
                    );
                }
            } catch (\Throwable $e) {
                report($e);
                Log::error('RunStrategicDiagnosis: Error on campaign '.$campaign->id.': '.$e->getMessage());
                $errors++;
            } finally {
                $lock->release();
            }
        }

        Log::info('RunStrategicDiagnosis: Completed', [
            'campaigns_checked' => $campaigns->count(),
            'total_findings' => $totalFindings,
            'auto_fixed' => $totalFixed,
            'alerts_sent' => $totalAlerts,
            'errors' => $errors,
        ]);

        $this->finishRun($runStart, actions: $totalFixed, errors: $errors, warnings: $totalAlerts,
            scope: $campaigns->count().' campaigns', details: ['findings' => $totalFindings]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RunStrategicDiagnosis: Job failed — '.$e->getMessage());
        $this->recordRunFailure($e);
    }

    private function hasLiveMetaPlacement(Campaign $campaign): bool
    {
        $customer = $campaign->customer;
        if (! $customer?->facebook_ads_account_id || $customer->is_sandbox || ! EnabledPlatform::isEnabled('facebook')
            || $campaign->hasPassedEndDate() || $campaign->spend_safety_hold || $customer->adSpendCredit()->whereNotNull('campaigns_paused_at')->exists()) {
            return false;
        }
        $provider = app(CampaignService::class, ['customer' => $customer])->getCampaign($campaign->facebook_ads_campaign_id);

        return ($provider['effective_status'] ?? null) === 'ACTIVE'
            && (string) ($provider['id'] ?? '') === $campaign->facebook_ads_campaign_id
            && (string) ($provider['account_id'] ?? '') === preg_replace('/^act_/', '', $customer->facebook_ads_account_id);
    }
}
