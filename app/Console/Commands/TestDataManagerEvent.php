<?php

namespace App\Console\Commands;

use App\Models\MccAccount;
use App\Services\GoogleAds\DataManagerService;
use Illuminate\Console\Command;

/**
 * Access + shape check for the Data Manager API.
 *
 * Defaults to a dry run (validateOnly=true) so nothing is ingested — use it to
 * confirm the MCC refresh token carries the datamanager scope and that the
 * request body is accepted. Pass --live to send a real conversion.
 *
 * Usage:
 *   php artisan datamanager:test-event
 *   php artisan datamanager:test-event --action=7598881112 --gclid=REAL_GCLID --live
 */
class TestDataManagerEvent extends Command
{
    protected $signature = 'datamanager:test-event
                            {--action= : Conversion action id (defaults to campaign_live from settings)}
                            {--gclid=DIAGNOSTIC_FAKE_GCLID_0000 : gclid to attach}
                            {--value=1 : Conversion value}
                            {--currency=USD : Currency code}
                            {--email= : Optional email for enhanced-conversion hashing}
                            {--live : Actually ingest instead of validateOnly dry run}';

    protected $description = 'Send a test event to the Google Data Manager API to verify access and request shape.';

    public function handle(): int
    {
        $mcc = MccAccount::getActive();
        if (! $mcc) {
            $this->error('No active MCC account.');
            return self::FAILURE;
        }

        $operatingAccountId = config('conversions.google_ads_customer_id');
        if (! $operatingAccountId) {
            $this->error('SPECTRA_GOOGLE_ADS_CUSTOMER_ID (config conversions.google_ads_customer_id) is not set.');
            return self::FAILURE;
        }

        // Default to the campaign_live conversion action if none supplied.
        $actionId = $this->option('action');
        if (! $actionId) {
            $resource = \App\Models\Setting::get('conversion_resource_name.campaign_live');
            $actionId = $resource ? (explode('/', $resource)[3] ?? null) : null;
        }
        if (! $actionId) {
            $this->error('No conversion action id — pass --action=<id>.');
            return self::FAILURE;
        }

        $validateOnly = ! $this->option('live');

        $this->info(sprintf(
            '%s event → operating=%s login=%s action=%s',
            $validateOnly ? 'DRY RUN (validateOnly)' : 'LIVE',
            $operatingAccountId,
            $mcc->google_customer_id,
            $actionId,
        ));

        $result = (new DataManagerService($mcc))->ingestGclidConversion(
            operatingAccountId: (string) $operatingAccountId,
            conversionActionId: (string) $actionId,
            gclid: $this->option('gclid'),
            value: (float) $this->option('value'),
            currency: $this->option('currency'),
            occurredAt: now(),
            email: $this->option('email') ?: null,
            validateOnly: $validateOnly,
        );

        if ($result['success']) {
            $this->info('SUCCESS — request accepted by Data Manager API.');
            $this->line('  requestId: ' . ($result['requestId'] ?? '(none)'));
            return self::SUCCESS;
        }

        $this->error('FAILED: ' . ($result['error'] ?? 'unknown error'));
        return self::FAILURE;
    }
}
