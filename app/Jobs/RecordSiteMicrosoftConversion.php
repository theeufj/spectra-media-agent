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
        'signup'           => 'Spectra Signup',
        'pricing_visit'    => 'Spectra Pricing Visit',
        'sandbox_launched' => 'Spectra Sandbox Launch',
        'campaign_live'    => 'Spectra Campaign Live',
        'seven_day_return' => 'Spectra 7-Day Return',
    ];

    public function __construct(
        protected User   $user,
        protected string $event
    ) {}

    public function handle(): void
    {
        if (!$this->user->msclid) {
            return;
        }

        $goalName = self::GOAL_MAP[$this->event] ?? null;
        if (!$goalName) {
            Log::debug("RecordSiteMicrosoftConversion: no goal mapping for '{$this->event}'");
            return;
        }

        $config = config("conversions.events.{$this->event}", []);

        // Use a minimal customer stub — Microsoft service only needs account credentials from config
        $customer = new \App\Models\Customer();
        $service  = new ConversionTrackingService($customer);

        try {
            $uploaded = $service->applyOfflineConversion(
                msclid: $this->user->msclid,
                goalName: $goalName,
                conversionTime: $this->user->created_at ?? now(),
                value: (float) ($config['value'] ?? 0),
                currencyCode: $config['currency'] ?? 'USD',
            );

            SpectraConversionEvent::record($this->event, $this->user->id, [
                'gclid'    => null,
                'fbclid'   => null,
                'mode'     => 'server_microsoft',
                'uploaded' => $uploaded,
            ]);

            if ($uploaded) {
                Log::info("RecordSiteMicrosoftConversion: uploaded '{$this->event}' for user {$this->user->id}");
            } else {
                Log::warning("RecordSiteMicrosoftConversion: upload returned false for '{$this->event}' user {$this->user->id}");
            }
        } catch (\Exception $e) {
            Log::error("RecordSiteMicrosoftConversion: failed for '{$this->event}': " . $e->getMessage(), [
                'user_id' => $this->user->id,
            ]);
        }
    }
}
