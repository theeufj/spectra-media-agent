<?php

namespace App\Jobs;

use App\Models\SpectraConversionEvent;
use App\Models\User;
use App\Services\FacebookAds\ConversionsApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fires a server-side Facebook CAPI event for a sitetospend.com own-site conversion.
 *
 * Dispatched when a user who arrived via a Facebook Ad (has a stored fbclid)
 * completes a meaningful action (signup, campaign_live, etc.).
 *
 * Requires:
 *  - $user->fbclid to be set (captured by CaptureClickIds middleware)
 *  - FACEBOOK_SYSTEM_USER_TOKEN and customer's facebook_pixel_id
 *
 * Uses the Spectra Business Manager's system user token — same credential
 * used for all Facebook API calls. The pixel must be the Spectra-owned pixel
 * (stored on the customer or sourced from the BM directly).
 */
class RecordSiteFacebookConversion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Map own-site event keys → Facebook standard event names
    private const EVENT_MAP = [
        'signup'           => 'CompleteRegistration',
        'pricing_visit'    => 'ViewContent',
        'sandbox_launched' => 'StartTrial',
        'campaign_live'    => 'Subscribe',
        'seven_day_return' => 'Purchase',
    ];

    public function __construct(
        protected User   $user,
        protected string $event
    ) {}

    public function handle(): void
    {
        if (!$this->user->fbclid) {
            return;
        }

        $pixelId = config('services.facebook.spectra_pixel_id');
        if (!$pixelId) {
            Log::debug('RecordSiteFacebookConversion: FACEBOOK_SPECTRA_PIXEL_ID not configured — skipping');
            return;
        }

        $fbEventName = self::EVENT_MAP[$this->event] ?? null;
        if (!$fbEventName) {
            Log::debug("RecordSiteFacebookConversion: no Facebook event mapping for '{$this->event}'");
            return;
        }

        $config = config("conversions.events.{$this->event}", []);

        // Construct a minimal customer stub so ConversionsApiService can instantiate
        $customer = new \App\Models\Customer();
        $service  = new ConversionsApiService($customer);

        $eventData = [
            'event_name'    => $fbEventName,
            'event_time'    => now()->timestamp,
            'event_id'      => Str::uuid()->toString(),
            'action_source' => 'website',
            'user_data'     => [
                'em'    => hash('sha256', strtolower(trim($this->user->email))),
                'fn'    => hash('sha256', strtolower(explode(' ', trim($this->user->name))[0])),
                'fbc'   => "fb.1.{$this->user->created_at->timestamp}.{$this->user->fbclid}",
                'client_ip_address' => null,
            ],
        ];

        if (!empty($config['value'])) {
            $eventData['custom_data'] = [
                'value'    => (float) $config['value'],
                'currency' => $config['currency'] ?? 'USD',
            ];
        }

        try {
            $result = $service->sendEvent($pixelId, $eventData);

            SpectraConversionEvent::record($this->event, $this->user->id, [
                'gclid'           => null,
                'fbclid'          => $this->user->fbclid,
                'mode'            => 'server_facebook',
                'uploaded'        => (bool) $result,
            ]);

            if ($result) {
                Log::info("RecordSiteFacebookConversion: sent '{$this->event}' ({$fbEventName}) for user {$this->user->id}");
            } else {
                Log::warning("RecordSiteFacebookConversion: CAPI returned null for '{$this->event}' user {$this->user->id}");
            }
        } catch (\Exception $e) {
            Log::error("RecordSiteFacebookConversion: failed for '{$this->event}': " . $e->getMessage(), [
                'user_id' => $this->user->id,
            ]);
        }
    }
}
