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
    private const TOKEN_ENDPOINT  = 'https://oauth2.googleapis.com/token';

    private ?MccAccount $mcc;

    public function __construct(?MccAccount $mcc = null)
    {
        $this->mcc = $mcc ?? MccAccount::getActive();
    }

    /**
     * Ingest a single gclid-keyed offline conversion into a Google Ads
     * conversion action.
     *
     * @param  string             $operatingAccountId  Ad account id (digits only, no dashes)
     * @param  string             $conversionActionId  Numeric conversion action id (productDestinationId)
     * @param  string             $gclid               Google click id
     * @param  float              $value               Conversion value
     * @param  string             $currency            ISO 4217 code
     * @param  \DateTimeInterface $occurredAt          When the conversion happened
     * @param  string|null        $email               Raw email — SHA-256 hashed here for enhanced matching
     * @param  bool               $validateOnly        Dry run: validate without ingesting
     * @return array{success:bool, requestId?:string, error?:string}
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
    ): array {
        if (! $this->mcc) {
            return ['success' => false, 'error' => 'No active MCC account'];
        }

        $token = $this->accessToken();
        if (! $token) {
            return ['success' => false, 'error' => 'Could not obtain Data Manager access token'];
        }

        $event = [
            'destinationReferences' => ['google_ads'],
            'eventTimestamp'        => Carbon::instance(Carbon::parse($occurredAt))->utc()->toIso8601ZuluString(),
            'adIdentifiers'         => ['gclid' => $gclid],
            'currency'              => $currency,
            'conversionValue'       => $value,
            // DMA consent. These are our own funnel conversions where the visitor
            // proceeded through the site; adjust if a real consent signal is available.
            'consent'               => [
                'adUserData'        => 'CONSENT_GRANTED',
                'adPersonalization' => 'CONSENT_GRANTED',
            ],
        ];

        // Enhanced matching: attach the SHA-256 hash of the normalized email.
        if ($email) {
            $event['userData'] = [
                'userIdentifiers' => [
                    ['emailAddress' => $this->hashEmail($email)],
                ],
            ];
        }

        $payload = [
            'validateOnly' => $validateOnly,
            'encoding'     => 'HEX', // encoding of any hashed userData values
            'destinations' => [[
                'reference'            => 'google_ads',
                'loginAccount'         => ['accountType' => 'GOOGLE_ADS', 'accountId' => (string) $this->mcc->google_customer_id],
                'operatingAccount'     => ['accountType' => 'GOOGLE_ADS', 'accountId' => $operatingAccountId],
                'productDestinationId' => $conversionActionId,
            ]],
            'events' => [$event],
        ];

        try {
            $response = Http::withToken($token)
                ->timeout(30)
                ->post(self::INGEST_ENDPOINT, $payload);

            if ($response->successful()) {
                return ['success' => true, 'requestId' => $response->json('requestId')];
            }

            Log::warning('DataManagerService: ingest failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
                'action' => $conversionActionId,
            ]);

            return ['success' => false, 'error' => "HTTP {$response->status()}: " . $response->body()];
        } catch (\Throwable $e) {
            Log::error('DataManagerService: ingest exception: ' . $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Exchange the MCC refresh token for a short-lived access token.
     * Reuses the same OAuth client credentials as the Google Ads API.
     */
    private function accessToken(): ?string
    {
        $cfg   = @parse_ini_file(storage_path('app/google_ads_php.ini'), true) ?: [];
        $oauth = $cfg['OAUTH2'] ?? [];

        if (empty($oauth['clientId']) || empty($oauth['clientSecret']) || ! $this->mcc?->refresh_token) {
            return null;
        }

        try {
            $response = Http::asForm()->timeout(15)->post(self::TOKEN_ENDPOINT, [
                'client_id'     => $oauth['clientId'],
                'client_secret' => $oauth['clientSecret'],
                'refresh_token' => $this->mcc->refresh_token,
                'grant_type'    => 'refresh_token',
            ]);

            return $response->json('access_token');
        } catch (\Throwable $e) {
            Log::error('DataManagerService: token exchange failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Normalize (trim + lowercase) and SHA-256 hash an email, hex-encoded,
     * per Data Manager's enhanced-conversion requirements.
     */
    private function hashEmail(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }
}
