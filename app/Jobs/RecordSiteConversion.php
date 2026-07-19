<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Services\GoogleAds\DataManagerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Uploads a server-side conversion to sitetospend.com's own Google Ads account
 * for users who arrived via a Google Ad (i.e. have a stored gclid).
 *
 * Server-side events (campaign_live, seven_day_return) cannot fire in the browser
 * because there's no page load — this job uploads them via the Data Manager API
 * (the legacy UploadClickConversions endpoint is closed to new integrations).
 *
 * The conversion action resource name must be provisioned (conversions:provision)
 * and stored in Settings before uploads occur. While it is null the job exits
 * cleanly without error.
 */
class RecordSiteConversion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected Customer $customer,
        protected string $event
    ) {}

    public function handle(): void
    {
        $resourceName = Setting::get("conversion_resource_name.{$this->event}");
        if (!$resourceName) {
            Log::debug("RecordSiteConversion: resource_name not in settings for '{$this->event}' — skipping");
            return;
        }

        // resource name format: customers/{operatingAccountId}/conversionActions/{conversionActionId}
        $parts              = explode('/', $resourceName);
        $operatingAccountId = $parts[1] ?? null;
        $conversionActionId = $parts[3] ?? null;
        if (!$operatingAccountId || !$conversionActionId) {
            Log::error("RecordSiteConversion: could not parse resource_name '{$resourceName}' for '{$this->event}'");
            return;
        }

        $config   = config("conversions.events.{$this->event}", []);
        $value    = (float) ($config['value'] ?? 0);
        $currency = $config['currency'] ?? 'USD';

        $users = $this->customer->users()->whereNotNull('gclid')->get();
        if ($users->isEmpty()) {
            return;
        }

        $dataManager = new DataManagerService();

        foreach ($users as $user) {
            $result = $dataManager->ingestGclidConversion(
                operatingAccountId: (string) $operatingAccountId,
                conversionActionId: (string) $conversionActionId,
                gclid: $user->gclid,
                value: $value,
                currency: $currency,
                occurredAt: now(),
                email: $user->email ?? null,
            );

            SpectraConversionEvent::record($this->event, $user->id, [
                'gclid'    => $user->gclid,
                'mode'     => 'server',
                'uploaded' => $result['success'],
            ]);

            if ($result['success']) {
                Log::info("RecordSiteConversion: uploaded '{$this->event}' for gclid {$user->gclid} (request " . ($result['requestId'] ?? 'n/a') . ')');
            } else {
                Log::warning("RecordSiteConversion: upload failed for '{$this->event}': " . ($result['error'] ?? 'unknown'), [
                    'gclid'    => $user->gclid,
                    'customer' => $this->customer->id,
                ]);
            }
        }
    }
}
