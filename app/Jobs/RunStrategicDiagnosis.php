<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\AgentActivity;
use App\Services\Agents\CampaignDiagnosticsAgent;
use App\Services\Agents\CampaignRemediationAgent;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \App\Jobs\Concerns\RecordsAgentRun;

    public int $tries   = 1;
    public int $timeout = 900; // 15 minutes

    public function handle(
        CampaignDiagnosticsAgent $diagnosticsAgent,
        CampaignRemediationAgent $remediationAgent
    ): void {
        Log::info('RunStrategicDiagnosis: Starting daily diagnosis pass');
        $runStart = $this->startRun();

        $campaigns = Campaign::with('customer')
            ->withDeployedPlatforms()
            ->whereNotIn('status', ['paused', 'draft', 'ended'])
            ->get();

        $totalFindings  = 0;
        $totalFixed     = 0;
        $totalAlerts    = 0;
        $errors         = 0;

        foreach ($campaigns as $campaign) {
            $lock = Cache::lock("strategic_diagnosis:campaign:{$campaign->id}", 3600);
            if (!$lock->get()) {
                continue;
            }

            try {
                $findings = $diagnosticsAgent->diagnose($campaign);

                if (empty($findings)) {
                    continue;
                }

                $totalFindings += count($findings);

                Log::info('RunStrategicDiagnosis: Findings for campaign ' . $campaign->id, [
                    'campaign' => $campaign->name,
                    'count'    => count($findings),
                    'types'    => array_column($findings, 'type'),
                ]);

                $result = $remediationAgent->remediate($campaign, $findings);

                $totalFixed  += count($result['actions_taken'] ?? []);
                $totalAlerts += count($result['alerts_sent'] ?? []);
                $errors      += count($result['errors'] ?? []);

                if (!empty($findings)) {
                    AgentActivity::record(
                        'strategic_diagnosis',
                        'diagnosed',
                        count($findings) . ' strategic issue(s) found in "' . $campaign->name . '"',
                        $campaign->customer_id,
                        $campaign->id,
                        [
                            'findings'       => array_map(fn($f) => ['type' => $f['type'], 'severity' => $f['severity']], $findings),
                            'actions_taken'  => $result['actions_taken'] ?? [],
                            'alerts_sent'    => $result['alerts_sent'] ?? [],
                        ]
                    );
                }
            } catch (\Exception $e) {
                Log::error('RunStrategicDiagnosis: Error on campaign ' . $campaign->id . ': ' . $e->getMessage());
                $errors++;
            } finally {
                $lock->release();
            }
        }

        Log::info('RunStrategicDiagnosis: Completed', [
            'campaigns_checked' => $campaigns->count(),
            'total_findings'    => $totalFindings,
            'auto_fixed'        => $totalFixed,
            'alerts_sent'       => $totalAlerts,
            'errors'            => $errors,
        ]);

        $this->finishRun($runStart, actions: $totalFixed, errors: $errors, warnings: $totalAlerts,
            scope: $campaigns->count() . ' campaigns', details: ['findings' => $totalFindings]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RunStrategicDiagnosis: Job failed — ' . $e->getMessage());
        $this->recordRunFailure($e);
    }
}
