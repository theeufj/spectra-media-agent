<?php

namespace App\Services\FacebookAds;

use App\Models\Customer;
use Illuminate\Support\Facades\Log;

/**
 * Facebook Custom Audiences Service
 * 
 * Handles:
 * - Customer list custom audiences (email/phone)
 * - Website custom audiences (pixel-based)
 * - Lookalike audience creation
 * 
 * Note: Customer data is hashed before being sent to Facebook per their requirements.
 */
class CustomAudienceService extends BaseFacebookAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Create a custom audience from a customer list (emails/phones).
     *
     * @param string $accountId Ad account ID (without 'act_' prefix)
     * @param string $name Audience name
     * @param string $description Audience description
     * @param array $emails List of customer emails (will be hashed)
     * @param array $phones List of customer phones (will be hashed)
     * @return array|null
     */
    public function createCustomerListAudience(
        string $accountId,
        string $name,
        string $description,
        array $emails = [],
        array $phones = []
    ): ?array {
        try {
            // Step 1: Create the custom audience container
            $audience = $this->post("/act_{$accountId}/customaudiences", [
                'name' => $name,
                'description' => $description,
                'subtype' => 'CUSTOM',
                'customer_file_source' => 'USER_PROVIDED_ONLY',
            ]);

            if (!$audience || !isset($audience['id'])) {
                Log::error('Failed to create custom audience container', [
                    'customer_id' => $this->customer->id,
                    'account_id' => $accountId,
                ]);
                return null;
            }

            $audienceId = $audience['id'];

            // Step 2: Add users to the audience
            $schema = [];
            $data = [];

            if (!empty($emails)) {
                $schema[] = 'EMAIL';
                foreach ($emails as $email) {
                    $data[] = [$this->hashValue(strtolower(trim($email)))];
                }
            }

            if (!empty($phones)) {
                $schema[] = 'PHONE';
                foreach ($phones as $index => $phone) {
                    $hashedPhone = $this->hashValue($this->normalizePhone($phone));
                    if (isset($data[$index])) {
                        $data[$index][] = $hashedPhone;
                    } else {
                        $data[] = [$hashedPhone];
                    }
                }
            }

            if (!empty($data)) {
                $this->addUsersToAudience($audienceId, $schema, $data);
            }

            Log::info('Created custom audience', [
                'customer_id' => $this->customer->id,
                'audience_id' => $audienceId,
                'name' => $name,
                'user_count' => count($data),
            ]);

            return $audience;

        } catch (\Exception $e) {
            Log::error('Error creating custom audience: ' . $e->getMessage(), [
                'exception' => $e,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Add users to an existing custom audience.
     *
     * @param string $audienceId
     * @param array $schema Schema array (e.g., ['EMAIL', 'PHONE'])
     * @param array $data User data (pre-hashed)
     * @return array|null
     */
    public function addUsersToAudience(string $audienceId, array $schema, array $data): ?array
    {
        try {
            // Facebook requires data in batches of 10,000 max
            $batches = array_chunk($data, 10000);
            $results = [];

            foreach ($batches as $batch) {
                $response = $this->post("/{$audienceId}/users", [
                    'payload' => json_encode([
                        'schema' => $schema,
                        'data' => $batch,
                    ]),
                ]);

                if ($response) {
                    $results[] = $response;
                }
            }

            Log::info('Added users to custom audience', [
                'audience_id' => $audienceId,
                'total_users' => count($data),
                'batches' => count($batches),
            ]);

            return $results[0] ?? null;

        } catch (\Exception $e) {
            Log::error('Error adding users to audience: ' . $e->getMessage(), [
                'audience_id' => $audienceId,
            ]);
            return null;
        }
    }

    /**
     * Remove users from an existing custom audience.
     *
     * @param string $audienceId
     * @param array $schema Schema array
     * @param array $data User data (pre-hashed)
     * @return array|null
     */
    public function removeUsersFromAudience(string $audienceId, array $schema, array $data): ?array
    {
        try {
            $response = $this->delete("/{$audienceId}/users", [
                'payload' => json_encode([
                    'schema' => $schema,
                    'data' => $data,
                ]),
            ]);

            Log::info('Removed users from custom audience', [
                'audience_id' => $audienceId,
                'user_count' => count($data),
            ]);

            return $response;

        } catch (\Exception $e) {
            Log::error('Error removing users from audience: ' . $e->getMessage(), [
                'audience_id' => $audienceId,
            ]);
            return null;
        }
    }

    /**
     * Create a website custom audience based on pixel events.
     *
     * @param string $accountId Ad account ID
     * @param string $pixelId Facebook Pixel ID
     * @param string $name Audience name
     * @param string $description Audience description
     * @param int $retentionDays Number of days to retain users (1-180)
     * @param array $rules Optional rules for audience (e.g., URL contains)
     * @return array|null
     */
    public function createWebsiteAudience(
        string $accountId,
        string $pixelId,
        string $name,
        string $description,
        int $retentionDays = 30,
        array $rules = []
    ): ?array {
        try {
            $rule = [
                'inclusions' => [
                    'operator' => 'or',
                    'rules' => empty($rules) ? [
                        [
                            'event_sources' => [['id' => $pixelId, 'type' => 'pixel']],
                            'retention_seconds' => $retentionDays * 86400,
                            'filter' => [
                                'operator' => 'and',
                                'filters' => [
                                    [
                                        'field' => 'event',
                                        'operator' => 'eq',
                                        'value' => 'PageView',
                                    ],
                                ],
                            ],
                        ],
                    ] : $rules,
                ],
            ];

            $response = $this->post("/act_{$accountId}/customaudiences", [
                'name' => $name,
                'description' => $description,
                'subtype' => 'WEBSITE',
                'rule' => json_encode($rule),
            ]);

            if ($response && isset($response['id'])) {
                Log::info('Created website custom audience', [
                    'customer_id' => $this->customer->id,
                    'audience_id' => $response['id'],
                    'pixel_id' => $pixelId,
                ]);
                return $response;
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Error creating website audience: ' . $e->getMessage(), [
                'exception' => $e,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Create a lookalike audience from a source audience.
     *
     * @param string $accountId Ad account ID
     * @param string $sourceAudienceId Source custom audience ID
     * @param string $name Audience name
     * @param string $countryCode Target country (ISO 2-letter code)
     * @param float $ratio Lookalike ratio (0.01 to 0.20 for 1%-20%)
     * @return array|null
     */
    public function createLookalikeAudience(
        string $accountId,
        string $sourceAudienceId,
        string $name,
        string $countryCode = 'US',
        float $ratio = 0.01
    ): ?array {
        try {
            $response = $this->post("/act_{$accountId}/customaudiences", [
                'name' => $name,
                'subtype' => 'LOOKALIKE',
                'origin_audience_id' => $sourceAudienceId,
                'lookalike_spec' => json_encode([
                    'type' => 'similarity',
                    'country' => $countryCode,
                    'ratio' => min(0.20, max(0.01, $ratio)),
                ]),
            ]);

            if ($response && isset($response['id'])) {
                Log::info('Created lookalike audience', [
                    'customer_id' => $this->customer->id,
                    'audience_id' => $response['id'],
                    'source_audience_id' => $sourceAudienceId,
                    'ratio' => $ratio,
                ]);
                return $response;
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Error creating lookalike audience: ' . $e->getMessage(), [
                'exception' => $e,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * List all custom audiences for an ad account.
     *
     * @param string $accountId
     * @return array|null
     */
    public function listAudiences(string $accountId): ?array
    {
        try {
            $response = $this->get("/act_{$accountId}/customaudiences", [
                'fields' => 'id,name,description,subtype,approximate_count,delivery_status,operation_status',
            ]);

            return $response['data'] ?? [];

        } catch (\Exception $e) {
            Log::error('Error listing custom audiences: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get details of a specific custom audience.
     *
     * @param string $audienceId
     * @return array|null
     */
    public function getAudience(string $audienceId): ?array
    {
        try {
            return $this->get("/{$audienceId}", [
                'fields' => 'id,name,description,subtype,approximate_count,delivery_status,operation_status,time_created,time_updated',
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting custom audience: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete a custom audience.
     *
     * @param string $audienceId
     * @return bool
     */
    public function deleteAudience(string $audienceId): bool
    {
        try {
            $response = $this->delete("/{$audienceId}");
            return $response['success'] ?? false;
        } catch (\Exception $e) {
            Log::error('Error deleting custom audience: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Hash a value using SHA256 as required by Facebook.
     *
     * @param string $value
     * @return string
     */
    protected function hashValue(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * Normalize a phone number to E.164 format (numbers only).
     *
     * @param string $phone
     * @return string
     */
    protected function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    /**
     * Make an HTTP DELETE request to the Facebook Graph API.
     *
     * @param string $endpoint
     * @param array $params
     * @return array|null
     */
    protected function delete(string $endpoint, array $params = []): ?array
    {
        try {
            if (!$this->accessToken) {
                return null;
            }

            $params['access_token'] = $this->accessToken;
            $url = $this->graphApiUrl . '/' . $this->apiVersion . $endpoint;

            $response = \Http::delete($url, $params);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error("Facebook API DELETE request failed", [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error("Exception during Facebook API DELETE request: " . $e->getMessage());
            return null;
        }
    }
}
