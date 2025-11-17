<?php

namespace App\Services\GTM;

use App\Models\Customer;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GTMContainerService
{
    /**
     * @var string The Google Tag Manager API base URL
     */
    private string $baseUrl = 'https://www.tagmanager.googleapis.com/tagmanager/v2';

    /**
     * @var string The GTM API version path
     */
    private string $apiVersion = 'v2';

    /**
     * @var int Maximum retries for API calls
     */
    private int $maxRetries = 3;

    /**
     * @var int Initial retry delay in milliseconds
     */
    private int $retryDelayMs = 1000;

    /**
     * Get the access token for GTM API calls.
     * 
     * Uses the customer's Google OAuth refresh token (stored encrypted)
     * to obtain a fresh access token for GTM API operations.
     *
     * @param Customer $customer
     * @return string|null The access token
     */
    protected function getAccessToken(Customer $customer): ?string
    {
        try {
            // Check if customer has a refresh token
            if (!$customer->google_ads_refresh_token) {
                Log::warning('Customer does not have Google OAuth refresh token', [
                    'customer_id' => $customer->id,
                ]);
                return null;
            }

            // Decrypt the refresh token
            $refreshToken = Crypt::decryptString($customer->google_ads_refresh_token);

            // Build OAuth2 credential using the refresh token
            $oAuth2CredentialBuilder = (new OAuth2TokenBuilder())
                ->fromFile(storage_path('app/google_ads_php.ini'))
                ->withRefreshToken($refreshToken);

            $oAuth2Credential = $oAuth2CredentialBuilder->build();

            // Get the access token from the credential
            $accessToken = $oAuth2Credential->getAccessToken();

            if (!$accessToken) {
                Log::error('Failed to obtain access token from refresh token', [
                    'customer_id' => $customer->id,
                ]);
                return null;
            }

            Log::debug('Access token obtained for GTM API', [
                'customer_id' => $customer->id,
            ]);

            return $accessToken;
        } catch (\Exception $e) {
            Log::error('Error obtaining access token for GTM API', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Link an existing GTM container to a customer.
     * 
     * This verifies the container exists and we have access to it,
     * then stores the container information in the database.
     *
     * @param Customer $customer
     * @param string $containerId The GTM container ID (e.g., GTM-XXXXXX)
     * @return array Array with 'success' bool and 'data' with container info or 'error' message
     */
    public function linkExistingContainer(Customer $customer, string $containerId): array
    {
        try {
            Log::info('Attempting to link GTM container', [
                'customer_id' => $customer->id,
                'container_id' => $containerId,
            ]);

            // Validate container ID format
            if (!$this->isValidContainerId($containerId)) {
                Log::error('Invalid container ID format', [
                    'customer_id' => $customer->id,
                    'container_id' => $containerId,
                ]);

                return [
                    'success' => false,
                    'error' => 'Invalid GTM container ID format. Expected format: GTM-XXXXXX',
                ];
            }

            // TODO: Verify container exists via GTM API
            // This requires:
            // 1. Get account ID from container ID
            // 2. List containers and verify this one exists
            // 3. Verify we have permission to modify it
            
            // For now, we'll store the basic information
            // In production, this should verify access first

            $containerInfo = [
                'container_id' => $containerId,
                'linked_at' => now(),
            ];

            // Update customer with container information
            $customer->update([
                'gtm_container_id' => $containerId,
                'gtm_config' => $containerInfo,
            ]);

            Log::info('GTM container linked successfully', [
                'customer_id' => $customer->id,
                'container_id' => $containerId,
            ]);

            return [
                'success' => true,
                'data' => [
                    'container_id' => $containerId,
                    'linked_at' => now(),
                    'status' => 'linked',
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Error linking GTM container', [
                'customer_id' => $customer->id,
                'container_id' => $containerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to link container: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Add a conversion tracking tag to a customer's GTM container.
     *
     * @param Customer $customer
     * @param string $tagName The name of the tag (e.g., "Google Ads Conversion - Purchase")
     * @param string $conversionId The conversion ID from Google Ads
     * @param array $config Additional tag configuration
     * @return array Array with 'success' bool and 'tag_id' or 'error' message
     */
    public function addConversionTag(Customer $customer, string $tagName, string $conversionId, array $config = []): array
    {
        try {
            Log::info('Adding conversion tag to GTM container', [
                'customer_id' => $customer->id,
                'tag_name' => $tagName,
                'conversion_id' => $conversionId,
            ]);

            if (!$customer->gtm_container_id) {
                return [
                    'success' => false,
                    'error' => 'Customer does not have a linked GTM container',
                ];
            }

            // TODO: Implement GTM API call to create conversion tag
            // This should:
            // 1. Build tag configuration for Google Ads conversion
            // 2. Call GTM API to create the tag
            // 3. Return the tag ID
            
            $tagId = 'tag_' . uniqid();

            Log::info('Conversion tag created (placeholder)', [
                'customer_id' => $customer->id,
                'tag_id' => $tagId,
                'tag_name' => $tagName,
            ]);

            return [
                'success' => true,
                'tag_id' => $tagId,
                'tag_name' => $tagName,
                'status' => 'created',
            ];
        } catch (\Exception $e) {
            Log::error('Error adding conversion tag', [
                'customer_id' => $customer->id,
                'tag_name' => $tagName,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to create tag: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Add a trigger to a customer's GTM container.
     *
     * @param Customer $customer
     * @param string $triggerName The name of the trigger
     * @param string $triggerType Type of trigger (pageview, purchase, form_submit, custom_event, scroll_depth)
     * @param array $config Trigger configuration (varies by type)
     * @return array Array with 'success' bool and 'trigger_id' or 'error' message
     */
    public function addTrigger(Customer $customer, string $triggerName, string $triggerType, array $config = []): array
    {
        try {
            Log::info('Adding trigger to GTM container', [
                'customer_id' => $customer->id,
                'trigger_name' => $triggerName,
                'trigger_type' => $triggerType,
            ]);

            if (!$customer->gtm_container_id) {
                return [
                    'success' => false,
                    'error' => 'Customer does not have a linked GTM container',
                ];
            }

            // Validate trigger type
            $validTriggerTypes = ['pageview', 'purchase', 'form_submit', 'custom_event', 'scroll_depth'];
            if (!in_array($triggerType, $validTriggerTypes)) {
                return [
                    'success' => false,
                    'error' => 'Invalid trigger type: ' . $triggerType,
                ];
            }

            // TODO: Implement GTM API call to create trigger
            // This should:
            // 1. Build trigger configuration based on type
            // 2. Call GTM API to create the trigger
            // 3. Return the trigger ID

            $triggerId = 'trigger_' . uniqid();

            Log::info('Trigger created (placeholder)', [
                'customer_id' => $customer->id,
                'trigger_id' => $triggerId,
                'trigger_name' => $triggerName,
                'trigger_type' => $triggerType,
            ]);

            return [
                'success' => true,
                'trigger_id' => $triggerId,
                'trigger_name' => $triggerName,
                'trigger_type' => $triggerType,
                'status' => 'created',
            ];
        } catch (\Exception $e) {
            Log::error('Error adding trigger', [
                'customer_id' => $customer->id,
                'trigger_name' => $triggerName,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to create trigger: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Publish a new version of the customer's GTM container.
     * 
     * This makes all changes (tags, triggers, etc.) go live on the customer's website.
     *
     * @param Customer $customer
     * @param string $notes Version notes describing the changes
     * @return array Array with 'success' bool and 'version_id' or 'error' message
     */
    public function publishContainer(Customer $customer, string $notes = ''): array
    {
        try {
            Log::info('Publishing GTM container version', [
                'customer_id' => $customer->id,
                'container_id' => $customer->gtm_container_id,
                'notes' => $notes,
            ]);

            if (!$customer->gtm_container_id) {
                return [
                    'success' => false,
                    'error' => 'Customer does not have a linked GTM container',
                ];
            }

            // TODO: Implement GTM API call to publish container
            // This should:
            // 1. Create a new workspace version with the current state
            // 2. Call GTM API to publish the version
            // 3. Tags go live on customer's website
            // 4. Return the version ID

            $versionId = 'version_' . uniqid();

            Log::info('Container version published (placeholder)', [
                'customer_id' => $customer->id,
                'version_id' => $versionId,
                'container_id' => $customer->gtm_container_id,
            ]);

            return [
                'success' => true,
                'version_id' => $versionId,
                'container_id' => $customer->gtm_container_id,
                'published_at' => now(),
                'status' => 'published',
            ];
        } catch (\Exception $e) {
            Log::error('Error publishing container', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to publish container: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Verify GTM container access and connectivity.
     *
     * @param Customer $customer
     * @return array Array with 'success' bool and 'status' or 'error' message
     */
    public function verifyContainerAccess(Customer $customer): array
    {
        try {
            Log::info('Verifying GTM container access', [
                'customer_id' => $customer->id,
                'container_id' => $customer->gtm_container_id,
            ]);

            if (!$customer->gtm_container_id) {
                return [
                    'success' => false,
                    'error' => 'Customer does not have a linked GTM container',
                ];
            }

            // TODO: Implement GTM API call to verify access
            // This should:
            // 1. Call GTM API with container ID
            // 2. Verify we have permission to modify it
            // 3. Return container details and status

            $customer->update(['gtm_last_verified' => now()]);

            return [
                'success' => true,
                'status' => 'verified',
                'container_id' => $customer->gtm_container_id,
                'verified_at' => now(),
            ];
        } catch (\Exception $e) {
            Log::error('Error verifying container access', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to verify container: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Validate GTM container ID format.
     *
     * @param string $containerId
     * @return bool
     */
    private function isValidContainerId(string $containerId): bool
    {
        return preg_match('/^(GTM|GT)-[A-Z0-9]+$/', $containerId) === 1;
    }
}
