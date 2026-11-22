<?php

namespace App\Jobs;

use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Models\User;
use App\Services\GoogleAds\DataManagerService;
use App\Services\GoogleAds\SignupImportDestination;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    public $tries = 8;

    public int $timeout = 70;

    // New Google actions can take 4–6 hours to accept uploads. Keep the fixed
    // event timestamp/transaction ID while retrying through that window.
    public $backoff = [60, 300, 1800, 3600, 7200, 10800, 21600];

    /**
     * Own-site event key → the UPLOAD_CLICKS conversion action it uploads to.
     * Client-side (WEBPAGE) events are absent by design: they fire via gtag and
     * uploading them as well would double-count.
     */
    private const UPLOAD_ACTIONS = [
        'signup' => 'signup_import',
        'paid_subscription' => 'paid_subscription',
        'paid_setup' => 'paid_setup',
        'campaign_live' => 'campaign_live',
        'seven_day_return' => 'seven_day_return',
    ];

    public static function actionKey(string $event): ?string
    {
        // Existing installations keep their reviewed Purchase mapping until the
        // dedicated action has been provisioned. A present but invalid mapping
        // must fail visibly instead of silently changing the intended product.
        if ($event === 'paid_setup' && Setting::get('conversion_resource_name.paid_setup') === null) {
            return 'paid_subscription';
        }

        return self::UPLOAD_ACTIONS[$event] ?? null;
    }

    public static function setupPurchaseDestination(): ?string
    {
        $resource = Setting::get('conversion_resource_name.paid_setup');
        if ($resource === null) {
            return self::canonicalPurchaseDestination();
        }

        return self::isCanonicalPurchaseDestination($resource)
            && $resource !== Setting::get('conversion_resource_name.paid_subscription') ? $resource : null;
    }

    public static function canonicalPurchaseDestination(): ?string
    {
        $resource = Setting::get('conversion_resource_name.paid_subscription');

        return self::isCanonicalPurchaseDestination($resource) ? $resource : null;
    }

    public static function isCanonicalPurchaseDestination(mixed $resource): bool
    {
        $account = (string) config('conversions.google_ads_customer_id');

        return ctype_digit($account) && is_string($resource)
            && preg_match('#^customers/'.preg_quote($account, '#').'/conversionActions/\d+$#', $resource) === 1;
    }

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

    public function middleware(): array
    {
        $timestamp = ($this->occurredAt ?? $this->user->created_at)?->getTimestamp();
        $key = $this->conversionEventId ?? "{$this->event}:{$this->user->id}:{$timestamp}";

        return [(new WithoutOverlapping("site-google-conversion:{$key}"))->releaseAfter(60)->expireAfter(90)];
    }

    public function handle(DataManagerService $dataManager): void
    {
        $eventId = $this->conversionEventId ?? null; // Also handles jobs queued before this field existed.
        $record = $eventId
            ? SpectraConversionEvent::where('user_id', $this->user->id)->where('event', $this->event)->findOrFail($eventId)
            : null;
        if ($this->event === 'signup') {
            // A pre-release queued job may lack the row ID. Never manufacture
            // registration-time consent from today's user preferences.
            $registrationKey = "signup:{$this->user->id}:{$this->user->created_at->timestamp}";
            $record ??= SpectraConversionEvent::where('deduplication_key', $registrationKey)->first();
            if (! $record || $record->mode !== 'server_google' || $record->deduplication_key !== $registrationKey
                || ($record->verified_source['kind'] ?? null) !== 'registration'
                || ($record->verified_source['user_id'] ?? null) !== $this->user->id || $this->user->tenant_key !== 'sitetospend') {
                return;
            }
        }
        if ($this->event === 'seven_day_return' && (! $record || ! str_starts_with((string) $record->deduplication_key, 'seven_day_return:'))) {
            return;
        }
        if (in_array($this->event, ['paid_subscription', 'paid_setup'], true) && ! $record) {
            throw new \LogicException('Paid conversions require a verified paid receipt.');
        }
        if ($this->event === 'paid_setup') {
            $cents = preg_match('/^(\d+)\.(\d{2})$/', $record->value, $amount)
                ? (int) $amount[1] * 100 + (int) $amount[2] : 0;
            if ($record->mode !== 'server_google' || ! preg_match('/^paid_setup:cs_[A-Za-z0-9_]+$/', $record->deduplication_key ?? '')
                || $record->currency !== 'USD' || $cents <= 0 || $cents > (int) config('services.stripe.setup_fee_usd_cents', 99900)
                || ! $record->occurred_at || $record->occurred_at->isFuture()) {
                throw new \LogicException('Paid setup imports require their verified positive Stripe checkout receipt.');
            }
        }

        $adIdentifiers = $record ? $record->ad_identifiers : $this->user->googleAdIdentifiers();
        if ($record?->uploaded_to_google) {
            CheckGoogleConversionProcessing::scheduleFor($record);

            return;
        }
        if ($this->event === 'signup' && (($record->verified_source['consent'] ?? null) !== true || $this->user->ad_measurement_consent !== true)) {
            if (($record->verified_source['consent'] ?? null) === true) {
                $record->update(['verified_source' => [...$record->verified_source, 'measurement_withdrawn' => true]]);
            }

            return;
        }
        if ($this->user->ad_measurement_consent === false) {
            return;
        }
        if (! $adIdentifiers && ! $record?->hasSignupEmailMatch()) {
            return;
        }
        if ($this->event === 'signup' && ! $adIdentifiers && ! $this->user->hasVerifiedEmail()) {
            return;
        }

        $actionKey = self::actionKey($this->event);
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
            CheckGoogleConversionProcessing::scheduleFor($record);

            return;
        }

        try {
            $resourceName = in_array($this->event, ['paid_setup', 'signup'], true) ? $record->google_conversion_resource
                : ($record->google_conversion_resource ?: Setting::get("conversion_resource_name.{$actionKey}"));
            if ($this->event === 'paid_setup' && ! self::isCanonicalPurchaseDestination($resourceName)) {
                throw new \RuntimeException('The paid setup receipt has no valid pinned canonical Purchase destination. Review its tracking configuration.');
            }
            if (! is_string($resourceName) || ! preg_match('~^customers/(\d+)/conversionActions/(\d+)$~', $resourceName, $parts)) {
                throw new \RuntimeException("Conversion action '{$actionKey}' is not provisioned.");
            }

            // Keep the attempted destination even when the request is rejected.
            // A destination is not a receipt; uploaded_to_google remains false
            // until Google accepts the event and returns a processing request ID.
            $record->update(['google_conversion_resource' => $resourceName]);

            $email = app(\App\Services\SiteSignupConversionService::class)->verifiedFormEmail($this->user, $this->event === 'signup' ? $record : null);
            $allowEmailOnly = false;
            if ($this->event === 'signup') {
                $enabled = app(SignupImportDestination::class)->allowsEmail($resourceName, $adIdentifiers ?? []);
                $allowEmailOnly = $enabled && $email !== null;
                if (! $allowEmailOnly) {
                    $email = null;
                    if (! $adIdentifiers) {
                        throw new \RuntimeException('Signup requires an eligible enhanced-conversion email match or a captured Google click ID.');
                    }
                }
            }
            $result = $dataManager->ingestConversion(
                operatingAccountId: $parts[1],
                conversionActionId: $parts[2],
                adIdentifiers: $record->ad_identifiers ?? [],
                value: (float) $record->value,
                currency: $record->currency,
                occurredAt: $record->occurred_at,
                email: $email,
                transactionId: $record->deduplication_key,
                consent: $this->user->ad_measurement_consent ? ['adUserData' => 'CONSENT_GRANTED'] : [],
                allowEmailOnly: $allowEmailOnly,
            );
            if (! $result['success']) {
                throw new \RuntimeException('Google conversion upload failed: '.($result['error'] ?? 'unknown error'));
            }
            if (! is_string($result['requestId'] ?? null) || trim($result['requestId']) === '') {
                throw new \RuntimeException('Google conversion upload returned no processing receipt. Delivery is unverified.');
            }

            // Accepted for processing is not proof of ad attribution. Keep the
            // provider request ID so delivery can be investigated separately.
            $record->update([
                'uploaded_to_google' => true,
                'google_request_id' => $result['requestId'],
                'google_conversion_resource' => $resourceName,
                'google_accepted_at' => now(),
                'google_processing_status' => null,
                'google_processing_attempts' => 0,
                'google_processing_details' => null,
                'upload_error' => null,
            ]);
            Log::info('Google conversion accepted for processing', [
                'event_id' => $record->id,
                'event' => $this->event,
                'request_id' => $result['requestId'],
            ]);
        } catch (\Throwable $e) {
            // Provider exceptions can carry raw request/response payloads. Only
            // persist our local messages and Google's scrubbed ingestion status.
            $message = $e::class === \RuntimeException::class ? $e->getMessage() : 'Google signup or delivery verification failed ('.class_basename($e).').';
            $record->update(['upload_error' => mb_substr($message, 0, 2000)]);
            // Let the queue retry and report final failure, rather than marking
            // a rejected upload as a successful job.
            throw $e;
        }
        // A queue outage retries this job; the saved receipt prevents another
        // ingestion and the early return above retries scheduling diagnostics.
        CheckGoogleConversionProcessing::scheduleFor($record);
    }
}
