<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\Customer;
use App\Models\Strategy;
use App\Notifications\CriticalAgentAlert;
use App\Services\Agents\AgentIssue;
use App\Services\GoogleAds\CampaignConversionTrackingHealth;
use App\Services\GoogleAds\ConversionTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VerifyConversionTracking implements ShouldQueue
{
    use \App\Jobs\Concerns\RecordsAgentRun, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 300;

    public function handle(): void
    {
        $runStart = $this->startRun();

        $customers = Customer::whereNotNull('google_ads_customer_id')
            ->whereHas('campaigns', fn ($q) => $q->where('status', 'active'))
            ->orderBy('id')
            ->get();

        $alerts = 0;
        $errors = 0;
        $warnings = 0;
        $checks = [];

        foreach ($customers as $customer) {
            $strategies = collect();
            try {
                $strategies = $customer->campaigns()->where('status', CampaignStatus::Active->value)
                    ->with(['customer', 'strategies' => fn ($q) => $q->deployed()
                        ->where(fn ($q) => $q->where('platform', 'like', '%google%')->orWhere('platform', 'like', '%Google%'))
                        ->orderBy('id')])
                    ->orderBy('id')
                    ->get()->reject(fn ($campaign) => $campaign->hasPassedEndDate()
                    || in_array(strtoupper($campaign->platform_status ?? ''), ['PAUSED', 'REMOVED'], true))
                    ->flatMap(fn ($campaign) => $campaign->strategies)
                    ->filter(fn (Strategy $strategy) => $strategy->reusableGoogleCampaignId() !== null);

                if ($strategies->isEmpty()) {
                    continue;
                }
                $service = $this->trackingService($customer);
                $accountActivity = $service->getAccountActivityLast30Days($customer->cleanGoogleCustomerId());
            } catch (\Throwable $e) {
                report($e);
                $errors++;
                $checks[] = ['customer_id' => $customer->id, 'strategy_ids' => $strategies->pluck('id')->all(),
                    'status' => 'unknown', 'checked_at' => now()->toIso8601String(),
                    'message' => 'The customer conversion check could not be completed. Conversion tracking health is unknown.',
                    'issues' => [(new AgentIssue('conversion_tracking_evidence_unavailable', 'The customer conversion check failed; unavailable data was not treated as zero conversions.'))->toArray()]];
                Log::error('VerifyConversionTracking: Account evidence unavailable', ['customer_id' => $customer->id, 'error' => $e->getMessage()]);

                continue;
            }

            foreach ($strategies as $strategy) {
                try {
                    $health = $this->healthService()->inspect($strategy, $service, $accountActivity);
                    $status = $health['status'] ?? 'unknown';
                    if (! in_array($status, ['inactive', 'not_verified', 'conversions_observed', 'needs_review', 'delivery_failed'], true)) {
                        throw new \UnexpectedValueException('Conversion tracking check returned no verified health classification.');
                    }
                    $checks[] = array_merge($health, ['customer_id' => $customer->id,
                        'campaign_id' => $strategy->campaign_id, 'strategy_id' => $strategy->id]);
                    $warnings += in_array($status, ['not_verified', 'needs_review', 'delivery_failed'], true) ? 1 : 0;
                    if (in_array($status, ['needs_review', 'delivery_failed'], true)) {
                        $this->alert($customer, $strategy, $health, $accountActivity);
                        $alerts++;
                    }
                    Log::info('VerifyConversionTracking: Selected action checked', ['customer_id' => $customer->id,
                        'campaign_id' => $strategy->campaign_id, 'strategy_id' => $strategy->id, 'status' => $status]);
                } catch (\Throwable $e) {
                    report($e);
                    $errors++;
                    $checks[] = ['customer_id' => $customer->id, 'campaign_id' => $strategy->campaign_id,
                        'strategy_id' => $strategy->id, 'status' => 'unknown', 'checked_at' => now()->toIso8601String(),
                        'message' => 'The campaign conversion check failed. Tracking health is unknown.',
                        'issues' => [(new AgentIssue('conversion_tracking_evidence_unavailable', 'The selected campaign conversion action could not be verified.'))->toArray()]];
                    Log::error('VerifyConversionTracking: Campaign evidence unavailable', ['customer_id' => $customer->id,
                        'campaign_id' => $strategy->campaign_id, 'strategy_id' => $strategy->id, 'error' => $e->getMessage()]);
                }
            }
        }

        $this->finishRun($runStart, actions: $alerts, errors: $errors, warnings: $warnings,
            scope: count($checks).' conversion tracking checks',
            note: 'Observed selected campaign actions; zero conversions alone do not establish a tracking fault.',
            details: ['checks' => $checks]);
    }

    protected function trackingService(Customer $customer): ConversionTrackingService
    {
        return app(ConversionTrackingService::class, ['customer' => $customer]);
    }

    protected function healthService(): CampaignConversionTrackingHealth
    {
        return app(CampaignConversionTrackingHealth::class);
    }

    private function alert(Customer $customer, Strategy $strategy, array $health, array $accountActivity): void
    {
        $campaign = $strategy->campaign;
        $issues = array_map(fn (AgentIssue $issue) => $issue->toArray(), AgentIssue::list($health['issues'] ?? []));
        $evidence = ['Account activity in the last 30 days: '.($accountActivity['impressions'] ?? 0).' impressions, '
            .($accountActivity['clicks'] ?? 0).' clicks, '.($accountActivity['conversions'] ?? 0).' in the Conversions column and '
            .($accountActivity['all_conversions'] ?? 0).' in the All conversions column across all actions. These account totals do not verify the campaign’s selected action.'];
        if (isset($health['selected_action_activity'])) {
            $selected = $health['selected_action_activity'];
            $evidence[] = 'This campaign’s selected action in the last 30 days: '.($selected['conversions'] ?? 0)
                .' in the Conversions column and '.($selected['all_conversions'] ?? 0).' in the All conversions column.';
        }
        if (! empty($health['intent']['action_resource'])) {
            $evidence[] = 'Selected conversion action: '.$health['intent']['action_resource'].'.';
        }
        $deliveryFailed = $health['status'] === 'delivery_failed';
        $action = $deliveryFailed
            ? 'Review the failed recorded server-side events, their Google upload or processing errors, and the selected conversion action. GTM installation alone does not verify server-side imports.'
            : 'Review the campaign’s selected conversion goal and reported configuration issues. Do not change bidding goals based only on a zero conversion count.';
        CriticalAgentAlert::deliver('conversion_tracking_health', 'Campaign conversion tracking needs review', $health['message'],
            ['customer_id' => $customer->id, 'campaign_id' => $campaign->id, 'campaign_name' => $campaign->name,
                'strategy_id' => $strategy->id, 'issues' => $issues, 'evidence_lines' => $evidence,
                'action_required' => $action, 'action_label' => 'Review campaign conversion evidence',
                'action_url' => route('campaigns.direct', $campaign),
                'dedupe_key' => hash('sha256', json_encode([$customer->id, $campaign->id, $strategy->id,
                    $health['status'], $health['intent']['action_resource'] ?? null, $issues], JSON_THROW_ON_ERROR))],
            CriticalAgentAlert::RECIPIENTS_ADMINS, $customer);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('VerifyConversionTracking failed: '.$exception->getMessage());
        $this->recordRunFailure($exception);
    }
}
