<?php

namespace App\Jobs;

use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Models\User;
use App\Services\GoogleAds\DataManagerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Uploads a server-side Google Ads conversion for a user who arrived via a
 * Google Ad, using the Data Manager API.
 *
 * Sibling of RecordSiteFacebookConversion / RecordSiteMicrosoftConversion,
 * which already covered fbclid and msclid — Google was the gap. Registration
 * signals had no server-side path at all: the code assumed gtag handled signup
 * client-side, but nothing ever called trackConversion('signup'), so across 22
 * real registrations Google recorded zero. With Maximize Conversions bidding on
 * an empty conversion history, the campaign lost ~90% of impression share to
 * Ad Rank.
 *
 * Server-side is the right home for this: registration completes on the server
 * where the gclid is already stored on the user, so it cannot be lost to ad
 * blockers, consent gating, or a redirect landing on a page that never fires.
 *
 * Targets an UPLOAD_CLICKS conversion action — Google rejects click uploads
 * against WEBPAGE-type actions.
 */
class RecordSiteGoogleConversion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 300];

    /**
     * Own-site event key → the UPLOAD_CLICKS conversion action it uploads to.
     * Client-side (WEBPAGE) events are absent by design: they fire via gtag and
     * uploading them as well would double-count.
     */
    private const UPLOAD_ACTIONS = [
        'signup' => 'signup_import',
        'paid_subscription' => 'paid_subscription',
        'campaign_live' => 'campaign_live',
        'seven_day_return' => 'seven_day_return',
    ];

    /**
     * $occurredAt is when the conversion happened, not when the job runs.
     *
     * Google attributes on that timestamp, so it must not drift with a retry —
     * hence a value fixed at dispatch rather than a `now()` inside handle(). It
     * defaults to the user's registration, which is what `signup` means; the
     * events that happen long afterwards (campaign_live, seven_day_return) pass
     * their own, as RecordSiteConversion did before it handed them over.
     */
    public function __construct(
        protected User $user,
        protected string $event,
        protected ?\DateTimeInterface $occurredAt = null,
        protected ?int $conversionEventId = null,
    ) {}

    public function handle(DataManagerService $dataManager): void
    {
        $eventId = $this->conversionEventId ?? null; // Also handles jobs queued before this field existed.
        $record = $eventId
            ? SpectraConversionEvent::where('user_id', $this->user->id)->where('event', $this->event)->findOrFail($eventId)
            : null;
        if ($this->event === 'paid_subscription' && ! $record) {
            throw new \LogicException('Paid conversions require a verified invoice event.');
        }

        $adIdentifiers = $record ? $record->ad_identifiers : $this->user->googleAdIdentifiers();
        if (! $adIdentifiers || $record?->uploaded_to_google) {
            return;
        }

        $actionKey = self::UPLOAD_ACTIONS[$this->event] ?? null;
        if (! $actionKey) {
            return;
        }

        $occurredAt = $this->occurredAt ?? $this->user->created_at ?? now();
        $config = config("conversions.events.{$this->event}", []);
        $record ??= SpectraConversionEvent::firstOrCreate([
            'deduplication_key' => "{$this->event}:{$this->user->id}:".$occurredAt->getTimestamp(),
        ], [
            'event' => $this->event,
            'user_id' => $this->user->id,
            'mode' => 'server_google',
            'value' => $config['value'] ?? 0,
            'currency' => $config['currency'] ?? 'USD',
            'ad_identifiers' => $adIdentifiers,
            'gclid' => reset($adIdentifiers),
            'occurred_at' => $occurredAt,
            'uploaded_to_google' => false,
        ]);

        if ($record->uploaded_to_google) {
            return;
        }

        try {
            $resourceName = Setting::get("conversion_resource_name.{$actionKey}");
            if (! is_string($resourceName) || ! preg_match('~^customers/(\d+)/conversionActions/(\d+)$~', $resourceName, $parts)) {
                throw new \RuntimeException("Conversion action '{$actionKey}' is not provisioned.");
            }

            $result = $dataManager->ingestConversion(
                operatingAccountId: $parts[1],
                conversionActionId: $parts[2],
                adIdentifiers: $record->ad_identifiers,
                value: (float) $record->value,
                currency: $record->currency,
                occurredAt: $record->occurred_at,
                email: $this->user->email,
                transactionId: $record->deduplication_key,
            );
            if (! $result['success']) {
                throw new \RuntimeException('Google conversion upload failed: '.($result['error'] ?? 'unknown error'));
            }

            // Accepted for processing is not proof of ad attribution. Keep the
            // provider request ID so delivery can be investigated separately.
            $record->update([
                'uploaded_to_google' => true,
                'google_request_id' => $result['requestId'] ?? null,
                'upload_error' => null,
            ]);
            Log::info('Google conversion accepted for processing', [
                'event_id' => $record->id,
                'event' => $this->event,
                'request_id' => $result['requestId'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $record->update(['upload_error' => mb_substr($e->getMessage(), 0, 2000)]);
            // Let the queue retry and report final failure, rather than marking
            // a rejected upload as a successful job.
            throw $e;
        }
    }
}
