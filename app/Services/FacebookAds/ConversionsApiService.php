<?php

namespace App\Services\FacebookAds;

use App\Models\Customer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Facebook Conversions API (CAPI) Service
 * 
 * Server-side event tracking for Facebook/Meta Ads.
 * This complements the client-side Pixel to ensure accurate attribution
 * even with iOS 14.5+ privacy changes and ad blockers.
 * 
 * Events are sent to the Conversions API and deduplicated with Pixel events
 * using the event_id parameter.
 */
class ConversionsApiService
{
    protected ?string $accessToken = null;
    protected ?Customer $customer = null;
    protected string $apiVersion = 'v18.0';
    protected string $graphApiUrl = 'https://graph.facebook.com';
    
    /**
     * Supported standard events
     */
    public const EVENT_PAGE_VIEW = 'PageView';
    public const EVENT_VIEW_CONTENT = 'ViewContent';
    public const EVENT_SEARCH = 'Search';
    public const EVENT_ADD_TO_CART = 'AddToCart';
    public const EVENT_ADD_TO_WISHLIST = 'AddToWishlist';
    public const EVENT_INITIATE_CHECKOUT = 'InitiateCheckout';
    public const EVENT_ADD_PAYMENT_INFO = 'AddPaymentInfo';
    public const EVENT_PURCHASE = 'Purchase';
    public const EVENT_LEAD = 'Lead';
    public const EVENT_COMPLETE_REGISTRATION = 'CompleteRegistration';
    public const EVENT_CONTACT = 'Contact';
    public const EVENT_FIND_LOCATION = 'FindLocation';
    public const EVENT_SCHEDULE = 'Schedule';
    public const EVENT_START_TRIAL = 'StartTrial';
    public const EVENT_SUBMIT_APPLICATION = 'SubmitApplication';
    public const EVENT_SUBSCRIBE = 'Subscribe';

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->accessToken = $this->getAccessToken();
    }

    /**
     * Get the Facebook access token from the customer record.
     */
    protected function getAccessToken(): ?string
    {
        try {
            if ($this->customer->facebook_ads_access_token) {
                return \Illuminate\Support\Facades\Crypt::decryptString($this->customer->facebook_ads_access_token);
            }
        } catch (\Exception $e) {
            Log::error("Failed to decrypt Facebook access token: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Send a single event to the Conversions API.
     *
     * @param string $pixelId The Facebook Pixel ID
     * @param array $eventData The event data
     * @return array|null
     */
    public function sendEvent(string $pixelId, array $eventData): ?array
    {
        return $this->sendEvents($pixelId, [$eventData]);
    }

    /**
     * Send multiple events to the Conversions API.
     *
     * @param string $pixelId The Facebook Pixel ID
     * @param array $events Array of event data
     * @return array|null
     */
    public function sendEvents(string $pixelId, array $events): ?array
    {
        if (!$this->accessToken) {
            Log::error('CAPI: No access token available');
            return null;
        }

        try {
            $formattedEvents = array_map([$this, 'formatEvent'], $events);

            $response = Http::post(
                "{$this->graphApiUrl}/{$this->apiVersion}/{$pixelId}/events",
                [
                    'access_token' => $this->accessToken,
                    'data' => json_encode($formattedEvents),
                ]
            );

            if ($response->successful()) {
                $result = $response->json();
                
                Log::info('CAPI: Events sent successfully', [
                    'pixel_id' => $pixelId,
                    'events_received' => $result['events_received'] ?? count($events),
                    'customer_id' => $this->customer->id,
                ]);
                
                return $result;
            }

            Log::error('CAPI: Failed to send events', [
                'pixel_id' => $pixelId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('CAPI: Exception sending events: ' . $e->getMessage(), [
                'pixel_id' => $pixelId,
            ]);
            return null;
        }
    }

    /**
     * Format an event for the Conversions API.
     *
     * @param array $event
     * @return array
     */
    protected function formatEvent(array $event): array
    {
        $formatted = [
            'event_name' => $event['event_name'],
            'event_time' => $event['event_time'] ?? time(),
            'action_source' => $event['action_source'] ?? 'website',
            'event_id' => $event['event_id'] ?? Str::uuid()->toString(),
        ];

        // User data (for matching)
        if (isset($event['user_data'])) {
            $formatted['user_data'] = $this->formatUserData($event['user_data']);
        }

        // Custom data (for event parameters)
        if (isset($event['custom_data'])) {
            $formatted['custom_data'] = $event['custom_data'];
        }

        // Event source URL
        if (isset($event['event_source_url'])) {
            $formatted['event_source_url'] = $event['event_source_url'];
        }

        // Opt out flag
        if (isset($event['opt_out'])) {
            $formatted['opt_out'] = $event['opt_out'];
        }

        return $formatted;
    }

    /**
     * Format and hash user data.
     *
     * @param array $userData
     * @return array
     */
    protected function formatUserData(array $userData): array
    {
        $formatted = [];

        // Hash personally identifiable information
        $hashFields = ['em', 'ph', 'fn', 'ln', 'db', 'ge', 'ct', 'st', 'zp', 'country'];
        
        foreach ($userData as $key => $value) {
            if (empty($value)) {
                continue;
            }

            if (in_array($key, $hashFields)) {
                // Normalize and hash
                $normalized = $this->normalizeValue($key, $value);
                $formatted[$key] = hash('sha256', $normalized);
            } elseif ($key === 'external_id') {
                // External ID should also be hashed
                $formatted[$key] = hash('sha256', $value);
            } else {
                // Non-PII fields (client_ip_address, client_user_agent, fbc, fbp)
                $formatted[$key] = $value;
            }
        }

        return $formatted;
    }

    /**
     * Normalize a value before hashing.
     *
     * @param string $key
     * @param string $value
     * @return string
     */
    protected function normalizeValue(string $key, string $value): string
    {
        $value = strtolower(trim($value));

        switch ($key) {
            case 'em': // Email
                return $value;
                
            case 'ph': // Phone
                return preg_replace('/[^0-9]/', '', $value);
                
            case 'fn': // First name
            case 'ln': // Last name
            case 'ct': // City
                return preg_replace('/[^a-z]/', '', $value);
                
            case 'st': // State
            case 'country':
                return preg_replace('/[^a-z]/', '', $value);
                
            case 'zp': // Zip code
                return preg_replace('/[^a-z0-9]/', '', $value);
                
            case 'ge': // Gender
                return in_array($value, ['m', 'f']) ? $value : '';
                
            case 'db': // Date of birth (YYYYMMDD)
                return preg_replace('/[^0-9]/', '', $value);
                
            default:
                return $value;
        }
    }

    /**
     * Send a PageView event.
     *
     * @param string $pixelId
     * @param array $userData User matching data
     * @param string $sourceUrl The page URL
     * @param string|null $eventId Event ID for deduplication with Pixel
     * @return array|null
     */
    public function sendPageView(
        string $pixelId,
        array $userData,
        string $sourceUrl,
        ?string $eventId = null
    ): ?array {
        return $this->sendEvent($pixelId, [
            'event_name' => self::EVENT_PAGE_VIEW,
            'event_time' => time(),
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_source_url' => $sourceUrl,
            'user_data' => $userData,
        ]);
    }

    /**
     * Send a Purchase event.
     *
     * @param string $pixelId
     * @param array $userData User matching data
     * @param float $value Purchase value
     * @param string $currency Currency code (e.g., 'USD')
     * @param string|null $orderId Order ID
     * @param array $contents Array of product contents
     * @param string|null $eventId Event ID for deduplication
     * @return array|null
     */
    public function sendPurchase(
        string $pixelId,
        array $userData,
        float $value,
        string $currency = 'USD',
        ?string $orderId = null,
        array $contents = [],
        ?string $eventId = null
    ): ?array {
        $customData = [
            'value' => $value,
            'currency' => $currency,
        ];

        if ($orderId) {
            $customData['order_id'] = $orderId;
        }

        if (!empty($contents)) {
            $customData['contents'] = $contents;
            $customData['num_items'] = count($contents);
        }

        return $this->sendEvent($pixelId, [
            'event_name' => self::EVENT_PURCHASE,
            'event_time' => time(),
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'user_data' => $userData,
            'custom_data' => $customData,
        ]);
    }

    /**
     * Send a Lead event.
     *
     * @param string $pixelId
     * @param array $userData User matching data
     * @param float|null $value Lead value (optional)
     * @param string $currency Currency code
     * @param string|null $eventId Event ID for deduplication
     * @return array|null
     */
    public function sendLead(
        string $pixelId,
        array $userData,
        ?float $value = null,
        string $currency = 'USD',
        ?string $eventId = null
    ): ?array {
        $customData = [];

        if ($value !== null) {
            $customData['value'] = $value;
            $customData['currency'] = $currency;
        }

        return $this->sendEvent($pixelId, [
            'event_name' => self::EVENT_LEAD,
            'event_time' => time(),
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'user_data' => $userData,
            'custom_data' => $customData ?: null,
        ]);
    }

    /**
     * Send an AddToCart event.
     *
     * @param string $pixelId
     * @param array $userData User matching data
     * @param float $value Cart value
     * @param string $currency Currency code
     * @param array $contents Product contents
     * @param string|null $eventId Event ID for deduplication
     * @return array|null
     */
    public function sendAddToCart(
        string $pixelId,
        array $userData,
        float $value,
        string $currency = 'USD',
        array $contents = [],
        ?string $eventId = null
    ): ?array {
        $customData = [
            'value' => $value,
            'currency' => $currency,
        ];

        if (!empty($contents)) {
            $customData['contents'] = $contents;
        }

        return $this->sendEvent($pixelId, [
            'event_name' => self::EVENT_ADD_TO_CART,
            'event_time' => time(),
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'user_data' => $userData,
            'custom_data' => $customData,
        ]);
    }

    /**
     * Send an InitiateCheckout event.
     *
     * @param string $pixelId
     * @param array $userData User matching data
     * @param float $value Cart value
     * @param string $currency Currency code
     * @param int $numItems Number of items
     * @param string|null $eventId Event ID for deduplication
     * @return array|null
     */
    public function sendInitiateCheckout(
        string $pixelId,
        array $userData,
        float $value,
        string $currency = 'USD',
        int $numItems = 1,
        ?string $eventId = null
    ): ?array {
        return $this->sendEvent($pixelId, [
            'event_name' => self::EVENT_INITIATE_CHECKOUT,
            'event_time' => time(),
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'user_data' => $userData,
            'custom_data' => [
                'value' => $value,
                'currency' => $currency,
                'num_items' => $numItems,
            ],
        ]);
    }

    /**
     * Send a CompleteRegistration event.
     *
     * @param string $pixelId
     * @param array $userData User matching data
     * @param string|null $status Registration status
     * @param string|null $eventId Event ID for deduplication
     * @return array|null
     */
    public function sendCompleteRegistration(
        string $pixelId,
        array $userData,
        ?string $status = null,
        ?string $eventId = null
    ): ?array {
        $customData = [];
        
        if ($status) {
            $customData['status'] = $status;
        }

        return $this->sendEvent($pixelId, [
            'event_name' => self::EVENT_COMPLETE_REGISTRATION,
            'event_time' => time(),
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'user_data' => $userData,
            'custom_data' => $customData ?: null,
        ]);
    }

    /**
     * Send a custom event.
     *
     * @param string $pixelId
     * @param string $eventName Custom event name
     * @param array $userData User matching data
     * @param array $customData Custom event data
     * @param string|null $eventId Event ID for deduplication
     * @return array|null
     */
    public function sendCustomEvent(
        string $pixelId,
        string $eventName,
        array $userData,
        array $customData = [],
        ?string $eventId = null
    ): ?array {
        return $this->sendEvent($pixelId, [
            'event_name' => $eventName,
            'event_time' => time(),
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'user_data' => $userData,
            'custom_data' => $customData ?: null,
        ]);
    }

    /**
     * Test the CAPI connection with a test event.
     *
     * @param string $pixelId
     * @param string $testCode Test event code from Events Manager
     * @return array|null
     */
    public function sendTestEvent(string $pixelId, string $testCode): ?array
    {
        if (!$this->accessToken) {
            return null;
        }

        try {
            $response = Http::post(
                "{$this->graphApiUrl}/{$this->apiVersion}/{$pixelId}/events",
                [
                    'access_token' => $this->accessToken,
                    'data' => json_encode([[
                        'event_name' => 'PageView',
                        'event_time' => time(),
                        'event_id' => Str::uuid()->toString(),
                        'action_source' => 'website',
                        'user_data' => [
                            'em' => hash('sha256', 'test@example.com'),
                            'client_ip_address' => '127.0.0.1',
                            'client_user_agent' => 'Test/1.0',
                        ],
                    ]]),
                    'test_event_code' => $testCode,
                ]
            );

            return $response->successful() ? $response->json() : null;

        } catch (\Exception $e) {
            Log::error('CAPI: Test event failed: ' . $e->getMessage());
            return null;
        }
    }
}
