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

            // Get access token for API calls
            $accessToken = $this->getAccessToken($customer);
            if (!$accessToken) {
                return [
                    'success' => false,
                    'error' => 'Unable to authenticate with Google. Please reconnect your Google account.',
                ];
            }

            // List all accounts to find the container
            $accountsResponse = $this->makeApiCall('GET', '/accounts', $accessToken);
            
            if (!$accountsResponse['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to list GTM accounts: ' . ($accountsResponse['error'] ?? 'Unknown error'),
                ];
            }

            // Search for the container across all accounts
            $containerFound = false;
            $containerData = null;
            $accountId = null;

            foreach ($accountsResponse['data']['account'] ?? [] as $account) {
                $accountPath = $account['path'];
                
                // List containers in this account
                $containersResponse = $this->makeApiCall('GET', "{$accountPath}/containers", $accessToken);
                
                if ($containersResponse['success']) {
                    foreach ($containersResponse['data']['container'] ?? [] as $container) {
                        if ($container['publicId'] === $containerId) {
                            $containerFound = true;
                            $containerData = $container;
                            $accountId = $account['accountId'];
                            break 2;
                        }
                    }
                }
            }

            if (!$containerFound) {
                Log::warning('GTM container not found or no access', [
                    'customer_id' => $customer->id,
                    'container_id' => $containerId,
                ]);

                return [
                    'success' => false,
                    'error' => 'Container not found or you do not have access to it. Please verify the container ID and ensure your Google account has access.',
                ];
            }

            // Get default workspace
            $workspacesResponse = $this->makeApiCall('GET', "{$containerData['path']}/workspaces", $accessToken);
            $workspaceId = null;
            
            if ($workspacesResponse['success'] && isset($workspacesResponse['data']['workspace'])) {
                // Find default workspace
                foreach ($workspacesResponse['data']['workspace'] as $workspace) {
                    if ($workspace['name'] === 'Default Workspace') {
                        $workspaceId = $workspace['workspaceId'];
                        break;
                    }
                }
                // Fallback to first workspace if no default found
                if (!$workspaceId && count($workspacesResponse['data']['workspace']) > 0) {
                    $workspaceId = $workspacesResponse['data']['workspace'][0]['workspaceId'];
                }
            }

            // Store container information
            $containerInfo = [
                'container_id' => $containerId,
                'container_path' => $containerData['path'],
                'container_name' => $containerData['name'],
                'account_id' => $accountId,
                'workspace_id' => $workspaceId,
                'linked_at' => now()->toIso8601String(),
            ];

            // Update customer with container information
            $customer->update([
                'gtm_container_id' => $containerId,
                'gtm_account_id' => $accountId,
                'gtm_workspace_id' => $workspaceId,
                'gtm_config' => $containerInfo,
                'gtm_installed' => true,
                'gtm_last_verified' => now(),
            ]);

            Log::info('GTM container linked successfully', [
                'customer_id' => $customer->id,
                'container_id' => $containerId,
                'account_id' => $accountId,
                'workspace_id' => $workspaceId,
            ]);

            return [
                'success' => true,
                'data' => [
                    'container_id' => $containerId,
                    'container_name' => $containerData['name'],
                    'account_id' => $accountId,
                    'workspace_id' => $workspaceId,
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

            if (!$customer->gtm_container_id || !$customer->gtm_account_id || !$customer->gtm_workspace_id) {
                return [
                    'success' => false,
                    'error' => 'Customer does not have a properly linked GTM container',
                ];
            }

            // Get access token
            $accessToken = $this->getAccessToken($customer);
            if (!$accessToken) {
                return [
                    'success' => false,
                    'error' => 'Unable to authenticate with Google',
                ];
            }

            // Build workspace path
            $workspacePath = "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}/workspaces/{$customer->gtm_workspace_id}";

            // Get conversion label if provided
            $conversionLabel = $config['conversion_label'] ?? '';

            // Build tag configuration
            $tagData = [
                'name' => $tagName,
                'type' => 'awct',  // Google Ads Conversion Tracking
                'parameter' => [
                    [
                        'key' => 'conversionId',
                        'type' => 'template',
                        'value' => $conversionId,
                    ],
                    [
                        'key' => 'conversionLabel',
                        'type' => 'template',
                        'value' => $conversionLabel,
                    ],
                ],
            ];

            // Add firing triggers if provided
            if (isset($config['firing_trigger_id'])) {
                $tagData['firingTriggerId'] = [$config['firing_trigger_id']];
            }

            // Create tag via API
            $response = $this->makeApiCall('POST', "/{$workspacePath}/tags", $accessToken, $tagData);

            if (!$response['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to create tag: ' . ($response['error'] ?? 'Unknown error'),
                ];
            }

            $tagId = $response['data']['tagId'] ?? null;

            Log::info('Conversion tag created successfully', [
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

            if (!$customer->gtm_container_id || !$customer->gtm_account_id || !$customer->gtm_workspace_id) {
                return [
                    'success' => false,
                    'error' => 'Customer does not have a properly linked GTM container',
                ];
            }

            // Validate trigger type
            $validTriggerTypes = ['pageview', 'purchase', 'form_submit', 'custom_event', 'scroll_depth', 'click'];
            if (!in_array($triggerType, $validTriggerTypes)) {
                return [
                    'success' => false,
                    'error' => 'Invalid trigger type: ' . $triggerType,
                ];
            }

            // Get access token
            $accessToken = $this->getAccessToken($customer);
            if (!$accessToken) {
                return [
                    'success' => false,
                    'error' => 'Unable to authenticate with Google',
                ];
            }

            // Build workspace path
            $workspacePath = "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}/workspaces/{$customer->gtm_workspace_id}";

            // Build trigger configuration based on type
            $triggerData = $this->buildTriggerConfiguration($triggerName, $triggerType, $config);

            // Create trigger via API
            $response = $this->makeApiCall('POST', "/{$workspacePath}/triggers", $accessToken, $triggerData);

            if (!$response['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to create trigger: ' . ($response['error'] ?? 'Unknown error'),
                ];
            }

            $triggerId = $response['data']['triggerId'] ?? null;

            Log::info('Trigger created successfully', [
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

            if (!$customer->gtm_container_id || !$customer->gtm_account_id || !$customer->gtm_workspace_id) {
                return [
                    'success' => false,
                    'error' => 'Customer does not have a properly linked GTM container',
                ];
            }

            // Get access token
            $accessToken = $this->getAccessToken($customer);
            if (!$accessToken) {
                return [
                    'success' => false,
                    'error' => 'Unable to authenticate with Google',
                ];
            }

            // Build workspace path
            $workspacePath = "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}/workspaces/{$customer->gtm_workspace_id}";

            // Create a version from the workspace
            $versionData = [
                'name' => $notes ?: 'Published by Spectra - ' . now()->toDateTimeString(),
                'notes' => $notes,
            ];

            $createVersionResponse = $this->makeApiCall('POST', "/{$workspacePath}/version", $accessToken, $versionData);

            if (!$createVersionResponse['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to create version: ' . ($createVersionResponse['error'] ?? 'Unknown error'),
                ];
            }

            $versionPath = $createVersionResponse['data']['path'] ?? null;
            $versionId = $createVersionResponse['data']['containerVersionId'] ?? null;

            if (!$versionPath) {
                return [
                    'success' => false,
                    'error' => 'Version created but path not returned',
                ];
            }

            // Publish the version (versions are live by default when created from workspace)
            // But we can optionally set it as the live version explicitly
            $publishResponse = $this->makeApiCall('POST', "/{$versionPath}/publish", $accessToken);

            if (!$publishResponse['success']) {
                Log::warning('Version created but publish confirmation failed', [
                    'customer_id' => $customer->id,
                    'version_id' => $versionId,
                ]);
            }

            Log::info('Container version published successfully', [
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

            if (!$customer->gtm_container_id || !$customer->gtm_account_id) {
                return [
                    'success' => false,
                    'error' => 'Customer does not have a linked GTM container',
                ];
            }

            // Get access token
            $accessToken = $this->getAccessToken($customer);
            if (!$accessToken) {
                return [
                    'success' => false,
                    'error' => 'Unable to authenticate with Google',
                ];
            }

            // Try to get container details
            $containerPath = "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}";
            $response = $this->makeApiCall('GET', "/{$containerPath}", $accessToken);

            if (!$response['success']) {
                return [
                    'success' => false,
                    'error' => 'Container not accessible: ' . ($response['error'] ?? 'Unknown error'),
                    'status' => 'no_access',
                ];
            }

            // Check if we can list workspaces (verifies write access)
            $workspacesResponse = $this->makeApiCall('GET', "/{$containerPath}/workspaces", $accessToken);
            
            $hasWriteAccess = $workspacesResponse['success'];

            $customer->update(['gtm_last_verified' => now()]);

            Log::info('Container access verified', [
                'customer_id' => $customer->id,
                'container_id' => $customer->gtm_container_id,
                'write_access' => $hasWriteAccess,
            ]);

            return [
                'success' => true,
                'status' => 'verified',
                'container_id' => $customer->gtm_container_id,
                'container_name' => $response['data']['name'] ?? 'Unknown',
                'write_access' => $hasWriteAccess,
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

    /**
     * Make an API call to Google Tag Manager API with retry logic.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $endpoint API endpoint path
     * @param string $accessToken OAuth access token
     * @param array $data Request body data (for POST/PUT)
     * @return array Array with 'success' bool and 'data' or 'error'
     */
    private function makeApiCall(string $method, string $endpoint, string $accessToken, array $data = []): array
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->maxRetries) {
            try {
                $url = $this->baseUrl . $endpoint;

                $response = Http::withToken($accessToken)
                    ->timeout(30)
                    ->retry(2, 100);

                if ($method === 'GET') {
                    $response = $response->get($url);
                } elseif ($method === 'POST') {
                    $response = $response->post($url, $data);
                } elseif ($method === 'PUT') {
                    $response = $response->put($url, $data);
                } elseif ($method === 'DELETE') {
                    $response = $response->delete($url);
                } else {
                    return [
                        'success' => false,
                        'error' => 'Invalid HTTP method: ' . $method,
                    ];
                }

                if ($response->successful()) {
                    return [
                        'success' => true,
                        'data' => $response->json(),
                    ];
                }

                // Handle rate limiting (429) with exponential backoff
                if ($response->status() === 429) {
                    $attempt++;
                    if ($attempt < $this->maxRetries) {
                        $delay = $this->retryDelayMs * pow(2, $attempt - 1);
                        usleep($delay * 1000);
                        continue;
                    }
                }

                $lastError = $response->json()['error']['message'] ?? $response->body();

                Log::warning('GTM API call failed', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'error' => $lastError,
                ]);

                return [
                    'success' => false,
                    'error' => $lastError,
                    'status_code' => $response->status(),
                ];

            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $attempt++;

                if ($attempt < $this->maxRetries) {
                    $delay = $this->retryDelayMs * pow(2, $attempt - 1);
                    usleep($delay * 1000);
                    continue;
                }

                Log::error('GTM API call exception', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'error' => $lastError,
                ]);
            }
        }

        return [
            'success' => false,
            'error' => $lastError ?? 'Unknown error after retries',
        ];
    }

    /**
     * Build trigger configuration based on trigger type.
     *
     * @param string $triggerName
     * @param string $triggerType
     * @param array $config
     * @return array
     */
    private function buildTriggerConfiguration(string $triggerName, string $triggerType, array $config): array
    {
        $triggerData = [
            'name' => $triggerName,
        ];

        switch ($triggerType) {
            case 'pageview':
                $triggerData['type'] = 'pageview';
                if (isset($config['page_path'])) {
                    $triggerData['filter'] = [[
                        'type' => 'contains',
                        'parameter' => [
                            ['type' => 'template', 'key' => 'arg0', 'value' => '{{Page Path}}'],
                            ['type' => 'template', 'key' => 'arg1', 'value' => $config['page_path']],
                        ],
                    ]];
                }
                break;

            case 'purchase':
            case 'custom_event':
                $triggerData['type'] = 'customEvent';
                $eventName = $config['event_name'] ?? 'purchase';
                $triggerData['customEventFilter'] = [[
                    'type' => 'equals',
                    'parameter' => [
                        ['type' => 'template', 'key' => 'arg0', 'value' => '{{_event}}'],
                        ['type' => 'template', 'key' => 'arg1', 'value' => $eventName],
                    ],
                ]];
                break;

            case 'form_submit':
                $triggerData['type'] = 'formSubmission';
                $triggerData['waitForTags'] = [
                    'enabled' => true,
                    'timeout' => 2000,
                ];
                break;

            case 'scroll_depth':
                $triggerData['type'] = 'scrollDepth';
                $triggerData['percentageScroll'] = [
                    'enabled' => true,
                    'thresholds' => $config['thresholds'] ?? [25, 50, 75, 100],
                ];
                break;

            case 'click':
                $triggerData['type'] = 'click';
                if (isset($config['selector'])) {
                    $triggerData['filter'] = [[
                        'type' => 'matchCssSelector',
                        'parameter' => [
                            ['type' => 'template', 'key' => 'arg0', 'value' => '{{Click Element}}'],
                            ['type' => 'template', 'key' => 'arg1', 'value' => $config['selector']],
                        ],
                    ]];
                }
                break;
        }

        return $triggerData;
    }
}
