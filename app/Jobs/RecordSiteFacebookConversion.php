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

/**
 * Fires a server-side Facebook CAPI event for a sitetospend.com own-site conversion.
 *
 * Dispatched when a user who arrived via a Facebook Ad (has a stored fbclid)
 * completes a meaningful action (signup, campaign_live, etc.).
 *
 * Requires:
 *  - $user->fbclid to be set (captured by CaptureClickIds middleware)
 *  - FACEBOOK_SYSTEM_USER_TOKEN and the Site to Spend dataset
 *
 * Uses the Spectra Business Manager's system user token — same credential
 * used for all Facebook API calls. The pixel must be the Spectra-owned pixel
 * (stored on the customer or sourced from the BM directly).
 */
class RecordSiteFacebookConversion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public array $backoff = [60, 300, 900, 1800, 3600];

    public function __construct(
        protected User $user,
        protected string $event,
        protected ?string $eventSourceUrl = null,
        protected ?string $clientUserAgent = null,
        protected ?int $receiptId = null,
    ) {}

    public function handle(): void
    {
        if (! $this->user->fbclid || $this->user->ad_measurement_consent === false) {
            return;
        }

        if ($this->user->tenant_key !== 'sitetospend') {
            return;
        }
        $source = $this->eventSourceUrl ?? null;
        $userAgent = $this->clientUserAgent ?? null;
        $signup = $this->event === 'signup';
        if ($signup && $this->user->ad_measurement_consent !== true) {
            return;
        }
        if ($signup && (! $source || parse_url($source, PHP_URL_HOST) !== 'sitetospend.com' || ! filled($userAgent))) {
            throw new \RuntimeException('The signup is missing its own-site browser context.');
        }
        if (! $signup && ! in_array($this->event, ['paid_setup', 'paid_subscription'], true)) {
            return;
        }
        $record = SpectraConversionEvent::where('user_id', $this->user->id)->where('event', $this->event)->whereNotNull('occurred_at')
            ->when($this->receiptId, fn ($query) => $query->whereKey($this->receiptId))->firstOrFail();
        if ($signup && (($record->verified_source['kind'] ?? null) !== 'registration'
            || ($record->verified_source['user_id'] ?? null) !== $this->user->id || ($record->verified_source['consent'] ?? null) !== true)) {
            return;
        }
        if (! $signup && (! $this->receiptId || $record->value <= 0 || ! str_starts_with((string) $record->deduplication_key, $this->event.':'))) {
            throw new \LogicException('An authentic positive Stripe receipt is required.');
        }
        $pixelId = (string) config('services.facebook.spectra_pixel_id');
        if (! ctype_digit($pixelId)) {
            throw new \RuntimeException('No own-site Meta dataset is configured.');
        }
        $eventName = $signup ? 'CompleteRegistration' : 'Purchase';
        $delivery = $record->meta_delivery;
        if (($delivery['status'] ?? '') === 'accepted') {
            return;
        }
        if ($delivery && ($delivery['dataset'] ?? null) !== $pixelId) {
            throw new \RuntimeException('The configured Meta dataset changed after registration.');
        }
        $id = hash('sha256', $record->deduplication_key);
        $delivery = ['dataset' => $pixelId, 'event_id' => $id, 'event' => $eventName, 'status' => 'pending'];
        $record->update(['meta_delivery' => $delivery]);
        try {
            $email = app(\App\Services\SiteSignupConversionService::class)->verifiedFormEmail($this->user, $signup ? $record : null);
            $result = (new ConversionsApiService(new \App\Models\Customer))->sendEvent($pixelId, [
                'event_name' => $eventName, 'event_time' => $record->occurred_at->timestamp,
                'event_id' => $id, 'action_source' => $signup ? 'website' : 'system_generated', 'event_source_url' => $source,
                ...($signup ? [] : ['custom_data' => ['value' => (float) $record->value, 'currency' => $record->currency]]),
                'user_data' => ['fbc' => 'fb.1.'.($this->user->created_at->timestamp * 1000).'.'.$this->user->fbclid,
                    'client_user_agent' => $userAgent, ...($email !== null ? ['em' => $email] : [])],
            ]);
            if (($result['events_received'] ?? null) !== 1 || empty($result['fbtrace_id'])) {
                throw new \RuntimeException('Meta did not acknowledge the registration event.');
            }
            $record->update(['meta_delivery' => array_merge($delivery, ['status' => 'accepted',
                'trace' => $result['fbtrace_id'], 'events_received' => 1, 'accepted_at' => now()->toIso8601String()])]);
        } catch (\Throwable $error) {
            $record->update(['meta_delivery' => array_merge($delivery, ['status' => 'failed'])]);
            throw $error;
        }
    }
}
