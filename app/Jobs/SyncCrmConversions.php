<?php

namespace App\Jobs;

use App\Models\CrmIntegration;
use App\Models\OfflineConversion;
use App\Services\Crm\CrmConnectorFactory;
use App\Support\SafeError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncCrmConversions implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public $uniqueFor = 900;

    public function uniqueId(): string
    {
        return (string) $this->crmIntegrationId;
    }

    public function __construct(
        protected int $crmIntegrationId
    ) {}

    public function handle(): void
    {
        $integration = CrmIntegration::find($this->crmIntegrationId);
        if (! $integration || ! $integration->isSyncable()) {
            return;
        }

        try {
            $integration->update(['status' => 'syncing']);

            $syncStarted = now();
            $connector = CrmConnectorFactory::make($integration);
            $since = $integration->last_synced_at ?? now()->subDays(30);
            $leads = $connector->fetchClosedLeads($since);

            $created = 0;
            $destinations = $this->destinations($integration);
            foreach ($leads as $lead) {
                $externalId = trim((string) ($lead['crm_lead_id'] ?? ''));
                if ($externalId === '') {
                    continue;
                }
                $key = hash('sha256', $integration->customer_id.':'.$integration->provider.':'.$externalId);
                $legacy = OfflineConversion::where('crm_integration_id', $integration->id)->where('crm_lead_id', $externalId)
                    ->whereNull('source_key')->exists();
                if ($legacy) {
                    continue;
                }
                $googleClick = isset($destinations['google']) ? ($lead['gclid'] ?? null) : null;
                $metaClick = isset($destinations['facebook']) ? ($lead['fbclid'] ?? null) : null;
                $currency = $lead['currency_code'] ?? null;
                $eligible = ($googleClick || $metaClick) && ($lead['precise_event_time'] ?? true) && ($lead['conversion_value'] ?? 0) > 0 && ($lead['ad_measurement_consent'] ?? false) === true && is_string($currency) && preg_match('/^[A-Z]{3}$/D', $currency);
                $record = OfflineConversion::firstOrCreate(['source_key' => $key], [
                    'customer_id' => $integration->customer_id, 'crm_integration_id' => $integration->id,
                    'gclid' => $eligible ? $googleClick : null, 'fbclid' => $eligible ? $metaClick : null,
                    'crm_lead_id' => $externalId, 'conversion_name' => $lead['conversion_name'],
                    'conversion_value' => $currency ? $lead['conversion_value'] : null, 'conversion_time' => $lead['conversion_time'],
                    'currency_code' => $currency ?: $integration->customer->billingCurrency(),
                    'upload_status' => $eligible ? 'pending' : 'first_party_only', 'crm_data' => $lead['crm_data'],
                    'delivery_bindings' => $eligible ? $destinations + ['ad_user_data' => 'granted'] : [],
                ]);
                $created += (int) $record->wasRecentlyCreated;
            }

            $integration->update([
                'status' => 'connected',
                'last_synced_at' => $syncStarted,
                'total_leads_synced' => $integration->total_leads_synced + $created,
                'last_error' => null,
            ]);

            Log::info('SyncCrmConversions: Synced', [
                'integration_id' => $integration->id,
                'leads_fetched' => count($leads),
                'conversions_created' => $created,
            ]);

            // Dispatch upload job for pending conversions
            if ($integration->offlineConversions()->where('upload_status', 'pending')->exists()) {
                UploadOfflineConversions::dispatch($integration->customer_id);
            }
        } catch (\Throwable $e) {
            $integration->update([
                'status' => 'error',
                'last_error' => SafeError::message($e, 'The CRM sync could not be completed.'),
            ]);
            report($e);
            Log::error('SyncCrmConversions: Failed', ['integration_id' => $integration->id]);
        }
    }

    /** Freeze only unambiguous, explicitly selected purchase destinations for CRM deals. */
    private function destinations(\App\Models\CrmIntegration $integration): array
    {
        $options = [];
        foreach ($integration->customer->campaigns()->with('strategies')->get()->pluck('strategies')->flatten() as $strategy) {
            $binding = app(\App\Services\Conversions\CampaignOutcomeEvidence::class)->binding($strategy);
            if ($binding['goal'] !== 'purchase' || ! $binding['destination']) {
                continue;
            }
            $options[$binding['channel']][$binding['destination']] = ['account' => $binding['account'],
                'destination' => $binding['destination'], 'event' => 'Purchase'];
        }

        return array_map(fn ($items) => reset($items), array_filter($options, fn ($items) => count($items) === 1));
    }

    public function failed(\Throwable $exception): void
    {
        // A hard failure (outside the handle() try/catch) can strand the integration
        // in 'syncing'. Reset it to 'error' so the scheduler retries it next run
        // instead of it being skipped forever.
        $integration = CrmIntegration::find($this->crmIntegrationId);
        if ($integration && $integration->status === 'syncing') {
            $integration->update([
                'status' => 'error',
                'last_error' => SafeError::message($exception, 'The CRM sync could not be completed.'),
            ]);
        }

        Log::error('SyncCrmConversions failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
