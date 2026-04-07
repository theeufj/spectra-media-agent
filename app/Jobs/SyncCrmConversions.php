<?php

namespace App\Jobs;

use App\Models\CrmIntegration;
use App\Models\OfflineConversion;
use App\Services\Crm\CrmConnectorFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncCrmConversions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $crmIntegrationId
    ) {}

    public function handle(): void
    {
        $integration = CrmIntegration::find($this->crmIntegrationId);
        if (!$integration || !$integration->isConnected()) return;

        try {
            $integration->update(['status' => 'syncing']);

            $connector = CrmConnectorFactory::make($integration);
            $since = $integration->last_synced_at ?? now()->subDays(30);
            $leads = $connector->fetchClosedLeads($since);

            $created = 0;
            foreach ($leads as $lead) {
                // Skip if already synced (by crm_lead_id)
                $exists = OfflineConversion::where('customer_id', $integration->customer_id)
                    ->where('crm_lead_id', $lead['crm_lead_id'])
                    ->exists();

                if ($exists) continue;

                // Only create if we have a click ID for attribution
                if (!$lead['gclid'] && !$lead['fbclid'] && !$lead['msclid']) continue;

                OfflineConversion::create([
                    'customer_id' => $integration->customer_id,
                    'crm_integration_id' => $integration->id,
                    'gclid' => $lead['gclid'],
                    'fbclid' => $lead['fbclid'],
                    'msclid' => $lead['msclid'],
                    'crm_lead_id' => $lead['crm_lead_id'],
                    'conversion_name' => $lead['conversion_name'],
                    'conversion_value' => $lead['conversion_value'],
                    'conversion_time' => $lead['conversion_time'],
                    'upload_status' => 'pending',
                    'crm_data' => $lead['crm_data'],
                ]);
                $created++;
            }

            $integration->update([
                'status' => 'connected',
                'last_synced_at' => now(),
                'total_leads_synced' => $integration->total_leads_synced + $created,
                'last_error' => null,
            ]);

            Log::info('SyncCrmConversions: Synced', [
                'integration_id' => $integration->id,
                'leads_fetched' => count($leads),
                'conversions_created' => $created,
            ]);

            // Dispatch upload job for pending conversions
            if ($created > 0) {
                UploadOfflineConversions::dispatch($integration->customer_id);
            }
        } catch (\Exception $e) {
            $integration->update([
                'status' => 'error',
                'last_error' => $e->getMessage(),
            ]);
            Log::error('SyncCrmConversions: Failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SyncCrmConversions failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
