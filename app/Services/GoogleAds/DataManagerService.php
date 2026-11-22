<?php

namespace App\Services\GoogleAds;

use App\Models\MccAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for the Google Data Manager API (datamanager.googleapis.com).
 *
 * This replaces the retired ConversionUploadService.UploadClickConversions path
 * for server-side / offline Google Ads conversions. It is a plain HTTPS client —
 * it deliberately does NOT extend BaseGoogleAdsService, because Data Manager is a
 * separate API from the Google Ads API (different host, no developer-token header,
 * account context carried in the request body rather than gRPC metadata).
 *
 * Auth reuses the MCC OAuth client + refresh token, which must have been granted
 * BOTH the adwords and datamanager scopes:
 *   https://www.googleapis.com/auth/adwords
 *   https://www.googleapis.com/auth/datamanager
 */
class DataManagerService
{
    private const INGEST_ENDPOINT = 'https://datamanager.googleapis.com/v1/events:ingest';

    private const STATUS_ENDPOINT = 'https://datamanager.googleapis.com/v1/requestStatus:retrieve';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private ?MccAccount $mcc;

    /** Cached access token for the lifetime of this instance (avoids re-exchanging per event). */
    private ?string $cachedToken = null;

    public function __construct(?MccAccount $mcc = null)
    {
        $this->mcc = $mcc ?? MccAccount::getActive();
    }

    /**
     * Ingest a single gclid-keyed offline conversion into a Google Ads
     * conversion action.
     *
     * Convenience wrapper for the common case. Prefer ingestConversion() where
     * the identifier may be gbraid or wbraid instead — on iOS it usually is.
     *
     * @param  string  $operatingAccountId  Ad account id (digits only, no dashes)
     * @param  string  $conversionActionId  Numeric conversion action id (productDestinationId)
     * @param  string  $gclid  Google click id
     * @param  float  $value  Conversion value
     * @param  string  $currency  ISO 4217 code
     * @param  \DateTimeInterface  $occurredAt  When the conversion happened
     * @param  string|null  $email  Raw email — SHA-256 hashed here for enhanced matching
     * @param  bool  $validateOnly  Dry run: validate without ingesting
     * @return array{success:bool, requestId?:string|null, error?:string}
     */
    public function ingestGclidConversion(
        string $operatingAccountId,
        string $conversionActionId,
        string $gclid,
        float $value,
        string $currency,
        \DateTimeInterface $occurredAt,
        ?string $email = null,
        bool $validateOnly = false,
        string $eventSource = 'WEB',
    ): array {
        return $this->ingestConversion(
            operatingAccountId: $operatingAccountId,
            conversionActionId: $conversionActionId,
            adIdentifiers: ['gclid' => $gclid],
            value: $value,
            currency: $currency,
            occurredAt: $occurredAt,
            email: $email,
            validateOnly: $validateOnly,
            eventSource: $eventSource,
        );
    }

    /**
     * Ingest a conversion keyed by whichever Google click identifier we hold.
     *
     * Google sends gclid normally, but wbraid (web-to-web) or gbraid (app-to-web)
     * where iOS ATT prevents it. They are mutually exclusive and equally valid
     * for attribution — accepting only gclid discards every iOS conversion.
     *
     * @param  array<string, string>  $adIdentifiers  One of gclid / gbraid / wbraid
     * @return array{success:bool, requestId?:string|null, error?:string}
     */
    public function ingestConversion(
        string $operatingAccountId,
        string $conversionActionId,
        array $adIdentifiers,
        float $value,
        string $currency,
        \DateTimeInterface $occurredAt,
        ?string $email = null,
        bool $validateOnly = false,
        string $eventSource = 'WEB',
        ?string $transactionId = null,
        array $consent = [],
        bool $allowEmailOnly = false,
    ): array {
        if (! $this->mcc) {
            return ['success' => false, 'error' => 'No active MCC account'];
        }

        $adIdentifiers = array_filter(
            $adIdentifiers,
            fn ($v, $k) => in_array($k, ['gclid', 'gbraid', 'wbraid'], true) && ! empty($v),
            ARRAY_FILTER_USE_BOTH
        );

        if ($adIdentifiers === [] && ! ($allowEmailOnly && $email && ($consent['adUserData'] ?? null) === 'CONSENT_GRANTED')) {
            return ['success' => false, 'error' => 'No usable Google click identifier (gclid/gbraid/wbraid)'];
        }

        $token = $this->accessToken();
        if (! $token) {
            return ['success' => false, 'error' => 'Could not obtain Data Manager access token'];
        }

        $event = [
            'destinationReferences' => ['google_ads'],
            'eventSource' => $eventSource, // required: WEB | APP | IN_STORE | PHONE | ...
            'eventTimestamp' => Carbon::instance(Carbon::parse($occurredAt))->utc()->toIso8601ZuluString(),
            'currency' => $currency,
            'conversionValue' => $value,
        ];

        if ($adIdentifiers !== []) {
            $event['adIdentifiers'] = $adIdentifiers;
        }

        // Only pass choices actually recorded at the source. Unknown is never opt-in.
        $choices = array_intersect_key($consent, array_flip(['adUserData', 'adPersonalization']));
        $choices = array_filter($choices, fn ($choice) => in_array($choice, ['CONSENT_GRANTED', 'CONSENT_DENIED'], true));
        if ($choices !== []) {
            $event['consent'] = $choices;
        }
        if (($choices['adUserData'] ?? null) === 'CONSENT_DENIED') {
            return ['success' => false, 'error' => 'Advertising data consent denied'];
        }

        if ($transactionId !== null) {
            $event['transactionId'] = $transactionId;
        }

        // Enhanced matching: attach the SHA-256 hash of the normalized email.
        if ($email && ($choices['adUserData'] ?? null) === 'CONSENT_GRANTED') {
            $event['userData'] = [
                'userIdentifiers' => [
                    ['emailAddress' => self::hashEmail($email)],
                ],
            ];
        }

        $payload = [
            'validateOnly' => $validateOnly,
            'encoding' => 'HEX', // encoding of any hashed userData values
            'destinations' => [[
                'reference' => 'google_ads',
                'loginAccount' => ['accountType' => 'GOOGLE_ADS', 'accountId' => (string) $this->mcc->google_customer_id],
                'operatingAccount' => ['accountType' => 'GOOGLE_ADS', 'accountId' => $operatingAccountId],
                'productDestinationId' => $conversionActionId,
            ]],
            'events' => [$event],
        ];

        try {
            $response = Http::withToken($token)
                ->timeout(30)
                ->post(self::INGEST_ENDPOINT, $payload);

            if ($response->successful()) {
                $requestId = $response->json('requestId');
                // validateOnly returns validation errors, not an ingestion receipt.
                // A live upload requires the processing ID Google documents.
                if (! $validateOnly && (! is_string($requestId) || trim($requestId) === '')) {
                    return ['success' => false, 'error' => 'Google returned no processing receipt. Delivery is unverified.'];
                }

                return ['success' => true, 'requestId' => is_string($requestId) ? $requestId : null];
            }

            // Error messages and field details can echo the submitted identifiers.
            $status = $response->json('error.status');
            $code = in_array($status, ['INVALID_ARGUMENT', 'UNAUTHENTICATED', 'PERMISSION_DENIED', 'NOT_FOUND',
                'RESOURCE_EXHAUSTED', 'FAILED_PRECONDITION', 'ABORTED', 'INTERNAL', 'UNAVAILABLE', 'DEADLINE_EXCEEDED'], true) ? $status : match ($response->status()) {
                    401 => 'UNAUTHENTICATED',
                    403 => 'PERMISSION_DENIED',
                    default => 'REQUEST_REJECTED',
                };
            Log::warning('DataManagerService: ingest failed', [
                'status' => $response->status(),
                'code' => $code,
                'action' => $conversionActionId,
            ]);

            return ['success' => false, 'error' => "HTTP {$response->status()} {$code}"];
        } catch (\Throwable $e) {
            Log::error('DataManagerService: ingest exception', ['exception' => $e::class]);

            return ['success' => false, 'error' => 'Google event ingestion could not be reached.'];
        }
    }

    /**
     * Read processing diagnostics for a real ingestion receipt. No conversions
     * are sent by this method. Raw provider payloads are not logged or saved.
     *
     * @return array{success:bool, destinations?:array, error?:string}
     */
    public function retrieveRequestStatus(string $requestId): array
    {
        $token = $this->accessToken();
        if (! $token) {
            return ['success' => false, 'error' => 'Could not obtain Data Manager access token'];
        }
        try {
            $response = Http::withToken($token)->timeout(30)->get(self::STATUS_ENDPOINT, ['requestId' => $requestId]);
            if (! $response->successful()) {
                return ['success' => false, 'error' => "Processing diagnostics unavailable (HTTP {$response->status()})."];
            }
            $destinations = $response->json('requestStatusPerDestination');
            if (! is_array($destinations) || $destinations === []) {
                return ['success' => false, 'error' => 'Google returned no destination processing status.'];
            }

            return ['success' => true, 'destinations' => $destinations];
        } catch (\Throwable $e) {
            Log::warning('Google conversion processing diagnostics unavailable', ['exception' => get_class($e)]);

            return ['success' => false, 'error' => 'Google processing diagnostics could not be reached.'];
        }
    }

    /**
     * Exchange the MCC refresh token for a short-lived access token.
     * Reuses the same OAuth client credentials as the Google Ads API.
     */
    private function accessToken(): ?string
    {
        if ($this->cachedToken) {
            return $this->cachedToken;
        }

        $cfg = @parse_ini_file(storage_path('app/google_ads_php.ini'), true) ?: [];
        $oauth = $cfg['OAUTH2'] ?? [];

        if (empty($oauth['clientId']) || empty($oauth['clientSecret']) || ! $this->mcc?->refresh_token) {
            return null;
        }

        try {
            $response = Http::asForm()->timeout(15)->post(self::TOKEN_ENDPOINT, [
                'client_id' => $oauth['clientId'],
                'client_secret' => $oauth['clientSecret'],
                'refresh_token' => $this->mcc->getDecryptedRefreshToken(),
                'grant_type' => 'refresh_token',
            ]);

            return $this->cachedToken = $response->json('access_token');
        } catch (\Throwable $e) {
            Log::error('DataManagerService: token exchange failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Normalize (trim + lowercase) and SHA-256 hash an email, hex-encoded,
     * per Data Manager's enhanced-conversion requirements.
     */
    public static function hashEmail(string $email): string
    {
        $normalized = mb_strtolower(trim($email));
        [$local, $domain] = array_pad(explode('@', $normalized, 2), 2, '');
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $normalized = str_replace('.', '', explode('+', $local, 2)[0]).'@'.$domain;
        }

        return hash('sha256', $normalized);
    }
}
