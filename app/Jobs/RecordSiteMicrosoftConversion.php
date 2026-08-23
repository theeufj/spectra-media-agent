<?php

namespace App\Jobs;

use App\Models\SpectraConversionEvent;
use App\Models\User;
use App\Services\MicrosoftAds\ConversionTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fires a server-side Microsoft Ads offline conversion for a sitetospend.com
 * own-site conversion event (signup, campaign_live, etc.).
 *
 * Requires the user to have a stored msclid (captured by CaptureClickIds middleware).
 * Uploads via the Microsoft Ads Campaign Management ApplyOfflineConversions API.
 */
class RecordSiteMicrosoftConversion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Map own-site event keys → Microsoft conversion goal names
    private const GOAL_MAP = [
        'signup' => 'Spectra Signup',
        'pricing_visit' => 'Spectra Pricing Visit',
        'sandbox_launched' => 'Spectra Sandbox Launch',
        'campaign_live' => 'Spectra Campaign Live',
        'seven_day_return' => 'Spectra 7-Day Return',
    ];

    public function __construct(
        protected User $user,
        protected string $event
    ) {}

    public function handle(): void
    {
        if (! $this->user->msclid) {
            return;
        }

        $goalName = self::GOAL_MAP[$this->event] ?? null;
        if (! $goalName) {
            Log::debug("RecordSiteMicrosoftConversion: no goal mapping for '{$this->event}'");

            return;
        }

        $config = config("conversions.events.{$this->event}", []);

        // Spectra's own advertising account, not a customer's.
        //
        // This used to be `new Customer` — an empty, unsaved stub. Every SOAP
        // call built its CustomerId / CustomerAccountId headers from that stub
        // (see BaseMicrosoftAdsService::apiCall), so each upload went to
        // Microsoft with both headers blank and attributed nothing.
        $accountId = config('conversions.microsoft_ads_account_id');
        $customerId = config('conversions.microsoft_ads_customer_id');

        if (! $accountId || ! $customerId) {
            Log::warning('RecordSiteMicrosoftConversion: Spectra Microsoft Ads account not configured — set SPECTRA_MICROSOFT_ADS_ACCOUNT_ID and SPECTRA_MICROSOFT_ADS_CUSTOMER_ID');

            return;
        }

        $spectraAccount = new \App\Models\Customer;
        $spectraAccount->microsoft_ads_account_id = $accountId;
        $spectraAccount->microsoft_ads_customer_id = $customerId;

        $service = new ConversionTrackingService($spectraAccount);

        try {
            $uploaded = $service->applyOfflineConversion(
                msclid: $this->user->msclid,
                goalName: $goalName,
                // The moment being reported, not when the account was opened.
                conversionTime: now(),
                value: (float) ($config['value'] ?? 0),
                currencyCode: $config['currency'] ?? 'USD',
            );

            // Only gclid/fbclid have columns on spectra_conversion_events, so
            // the msclid that drove this one is not stored — it is in the log
            // line below and on the user record.
            SpectraConversionEvent::record($this->event, $this->user->id, [
                'mode' => 'server_microsoft',
                'uploaded' => $uploaded,
            ]);

            if ($uploaded) {
                Log::info("RecordSiteMicrosoftConversion: uploaded '{$this->event}' for user {$this->user->id}");
            } else {
                Log::warning("RecordSiteMicrosoftConversion: upload returned false for '{$this->event}' user {$this->user->id}");
            }
        } catch (\Throwable $e) {
            report($e);
            Log::error("RecordSiteMicrosoftConversion: failed for '{$this->event}': ".$e->getMessage(), [
                'user_id' => $this->user->id,
            ]);
        }
    }
}
