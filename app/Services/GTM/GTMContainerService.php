<?php

namespace App\Services\GTM;

use App\Models\Customer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GTMContainerService
{
    private string $baseUrl = 'https://tagmanager.googleapis.com/tagmanager/v2';
    private int $maxRetries = 3;
    private int $retryDelayMs = 1000;

    /**
     * Get an access token using the PLATFORM's refresh token.
     *
     * GTM containers are owned and managed by the platform's Google account.
     * No per-user OAuth is required.
     */
    protected function getPlatformAccessToken(): ?string
    {
        try {
            $refreshToken = config('services.gtm.platform_refresh_token');
            $clientId     = config('services.google.client_id');
            $clientSecret = config('services.google.client_secret');

            if (!$refreshToken || !$clientId || !$clientSecret) {
                Log::warning('GTMContainerService: Missing GTM platform credentials (GTM_PLATFORM_REFRESH_TOKEN, GOOGLE_OAUTH_CLIENT_ID, or GOOGLE_OAUTH_CLIENT_SECRET)');
                return null;
            }

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
            ]);

            if (!$response->successful()) {
                Log::error('GTMContainerService: Failed to exchange refresh token', [
                    'status' => $response->status(),
                    'error'  => $response->json()['error'] ?? $response->body(),
                ]);
                return null;
            }

            return $response->json()['access_token'] ?? null;
        } catch (\Exception $e) {
            Log::error('GTMContainerService: Failed to get platform access token', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Provision a new GTM container for a customer under the platform's GTM account.
     *
     * Creates a container, stores its ID on the customer record, and returns
     * the GTM container public ID (e.g. GTM-XXXXXXX) ready for snippet generation.
     */
    public function provisionContainerForCustomer(Customer $customer): array
    {
        try {
            $accountId = config('services.gtm.platform_account_id');

            if (!$accountId) {
                return ['success' => false, 'error' => 'GTM_PLATFORM_ACCOUNT_ID is not configured'];
            }

            $accessToken = $this->getPlatformAccessToken();
            if (!$accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            $containerName = $customer->name . ' — Site to Spend';
            $response = $this->makeApiCall('POST', "/accounts/{$accountId}/containers", $accessToken, [
                'name' => $containerName,
                'usageContext' => ['WEB'],
            ]);

            if (!$response['success']) {
                return ['success' => false, 'error' => 'Failed to create GTM container: ' . ($response['error'] ?? 'Unknown error')];
            }

            $containerId   = $response['data']['publicId'] ?? null;  // GTM-XXXXXXX
            $containerPath = $response['data']['path'] ?? null;       // accounts/.../containers/...

            if (!$containerId) {
                return ['success' => false, 'error' => 'Container created but no publicId returned'];
            }

            // Get the default workspace ID
            $workspacesResponse = $this->makeApiCall('GET', "/{$containerPath}/workspaces", $accessToken);
            $workspaceId = null;
            if ($workspacesResponse['success']) {
                foreach ($workspacesResponse['data']['workspace'] ?? [] as $ws) {
                    if ($ws['name'] === 'Default Workspace') {
                        $workspaceId = $ws['workspaceId'];
                        break;
                    }
                }
                if (!$workspaceId && !empty($workspacesResponse['data']['workspace'])) {
                    $workspaceId = $workspacesResponse['data']['workspace'][0]['workspaceId'];
                }
            }

            $customer->update([
                'gtm_container_id'   => $containerId,
                'gtm_account_id'     => $accountId,
                'gtm_workspace_id'   => $workspaceId,
                'gtm_installed'      => false,
                'gtm_last_verified'  => null,
                'gtm_config'         => [
                    'container_id'   => $containerId,
                    'container_path' => $containerPath,
                    'container_name' => $containerName,
                    'account_id'     => $accountId,
                    'workspace_id'   => $workspaceId,
                    'provisioned_at' => now()->toIso8601String(),
                ],
            ]);

            Log::info('GTMContainerService: Container provisioned', [
                'customer_id'  => $customer->id,
                'container_id' => $containerId,
                'account_id'   => $accountId,
            ]);

            return [
                'success'      => true,
                'container_id' => $containerId,
                'workspace_id' => $workspaceId,
            ];
        } catch (\Exception $e) {
            Log::error('GTMContainerService: Error provisioning container', [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Return the two HTML snippets (head + body) for a given GTM container ID.
     */
    public function getSnippetHtml(string $containerId): array
    {
        $head = <<<HTML
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','{$containerId}');</script>
<!-- End Google Tag Manager -->
HTML;

        $body = <<<HTML
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id={$containerId}"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
HTML;

        return ['head' => $head, 'body' => $body];
    }

    /**
     * Add a Google Ads conversion tracking tag to the customer's container.
     */
    public function addConversionTag(Customer $customer, string $tagName, string $conversionId, array $config = []): array
    {
        try {
            if (!$customer->gtm_container_id || !$customer->gtm_account_id || !$customer->gtm_workspace_id) {
                return ['success' => false, 'error' => 'Customer does not have a provisioned GTM container'];
            }

            $accessToken = $this->getPlatformAccessToken();
            if (!$accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            $workspacePath = "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}/workspaces/{$customer->gtm_workspace_id}";

            $tagData = [
                'name' => $tagName,
                'type' => 'awct',
                'parameter' => [
                    ['key' => 'conversionId',    'type' => 'template', 'value' => $conversionId],
                    ['key' => 'conversionLabel', 'type' => 'template', 'value' => $config['conversion_label'] ?? ''],
                ],
            ];

            if (isset($config['firing_trigger_id'])) {
                $tagData['firingTriggerId'] = [$config['firing_trigger_id']];
            }

            $response = $this->makeApiCall('POST', "/{$workspacePath}/tags", $accessToken, $tagData);

            if (!$response['success']) {
                return ['success' => false, 'error' => 'Failed to create tag: ' . ($response['error'] ?? 'Unknown error')];
            }

            return [
                'success' => true,
                'tag_id'  => $response['data']['tagId'] ?? null,
                'tag_name' => $tagName,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Add a Meta Pixel base code tag to the customer's GTM container.
     * Fires on all pages. Creates the pixel with fbq('init') + fbq('track', 'PageView').
     */
    public function addFacebookPixelTag(Customer $customer, string $pixelId): array
    {
        try {
            if (!$customer->gtm_container_id || !$customer->gtm_account_id || !$customer->gtm_workspace_id) {
                return ['success' => false, 'error' => 'Customer does not have a provisioned GTM container'];
            }

            $accessToken = $this->getPlatformAccessToken();
            if (!$accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            $workspacePath = "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}/workspaces/{$customer->gtm_workspace_id}";

            $pixelScript = <<<JS
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init','{$pixelId}');
fbq('track','PageView');
</script>
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id={$pixelId}&ev=PageView&noscript=1"/></noscript>
JS;

            $tagData = [
                'name' => 'Spectra — Meta Pixel Base Code',
                'type' => 'html',
                'parameter' => [
                    ['key' => 'html',        'type' => 'template', 'value' => $pixelScript],
                    ['key' => 'supportDocumentWrite', 'type' => 'boolean', 'value' => 'false'],
                ],
                'firingTriggerId' => ['2147479553'], // GTM built-in "All Pages" trigger ID
            ];

            $response = $this->makeApiCall('POST', "/{$workspacePath}/tags", $accessToken, $tagData);

            if (!$response['success']) {
                return ['success' => false, 'error' => 'Failed to create Meta Pixel tag: ' . ($response['error'] ?? 'Unknown error')];
            }

            return ['success' => true, 'tag_id' => $response['data']['tagId'] ?? null];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Add a Microsoft UET base code tag to the customer's GTM container.
     * Fires on all pages.
     */
    public function addMicrosoftUetTag(Customer $customer, string $uetTagId): array
    {
        try {
            if (!$customer->gtm_container_id || !$customer->gtm_account_id || !$customer->gtm_workspace_id) {
                return ['success' => false, 'error' => 'Customer does not have a provisioned GTM container'];
            }

            $accessToken = $this->getPlatformAccessToken();
            if (!$accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            $workspacePath = "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}/workspaces/{$customer->gtm_workspace_id}";

            $uetScript = <<<JS
<script>
(function(w,d,t,r,u){var f,n,i;w[u]=w[u]||[],f=function(){var o={ti:"{$uetTagId}",enableAutoSpaTracking:true};
o.q=w[u],w[u]=new UET(o),w[u].push("pageLoad")},n=d.createElement(t),n.src=r,n.async=1,
n.onload=n.onreadystatechange=function(){var s=this.readyState;
s&&s!=="loaded"&&s!=="complete"||(f(),n.onload=n.onreadystatechange=null)},
i=d.getElementsByTagName(t)[0],i.parentNode.insertBefore(n,i)
})(window,document,"script","//bat.bing.com/bat.js","uetq");
</script>
JS;

            $tagData = [
                'name' => 'Spectra — Microsoft UET Base Code',
                'type' => 'html',
                'parameter' => [
                    ['key' => 'html',        'type' => 'template', 'value' => $uetScript],
                    ['key' => 'supportDocumentWrite', 'type' => 'boolean', 'value' => 'false'],
                ],
                'firingTriggerId' => ['2147479553'], // GTM built-in "All Pages" trigger ID
            ];

            $response = $this->makeApiCall('POST', "/{$workspacePath}/tags", $accessToken, $tagData);

            if (!$response['success']) {
                return ['success' => false, 'error' => 'Failed to create Microsoft UET tag: ' . ($response['error'] ?? 'Unknown error')];
            }

            return ['success' => true, 'tag_id' => $response['data']['tagId'] ?? null];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Add a trigger to the customer's GTM container.
     */
    public function addTrigger(Customer $customer, string $triggerName, string $triggerType, array $config = []): array
    {
        try {
            if (!$customer->gtm_container_id || !$customer->gtm_account_id || !$customer->gtm_workspace_id) {
                return ['success' => false, 'error' => 'Customer does not have a provisioned GTM container'];
            }

            $accessToken = $this->getPlatformAccessToken();
            if (!$accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            $workspacePath = "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}/workspaces/{$customer->gtm_workspace_id}";
            $triggerData   = $this->buildTriggerConfiguration($triggerName, $triggerType, $config);
            $response      = $this->makeApiCall('POST', "/{$workspacePath}/triggers", $accessToken, $triggerData);

            if (!$response['success']) {
                return ['success' => false, 'error' => 'Failed to create trigger: ' . ($response['error'] ?? 'Unknown error')];
            }

            return [
                'success'      => true,
                'trigger_id'   => $response['data']['triggerId'] ?? null,
                'trigger_type' => $triggerType,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Publish the current workspace as a new live version.
     */
    public function publishContainer(Customer $customer, string $notes = ''): array
    {
        try {
            if (!$customer->gtm_container_id || !$customer->gtm_account_id || !$customer->gtm_workspace_id) {
                return ['success' => false, 'error' => 'Customer does not have a provisioned GTM container'];
            }

            $accessToken = $this->getPlatformAccessToken();
            if (!$accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            $workspacePath = "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}/workspaces/{$customer->gtm_workspace_id}";

            $createVersionResponse = $this->makeApiCall('POST', "/{$workspacePath}/version", $accessToken, [
                'name'  => $notes ?: 'Published by Site to Spend — ' . now()->toDateTimeString(),
                'notes' => $notes,
            ]);

            if (!$createVersionResponse['success']) {
                return ['success' => false, 'error' => 'Failed to create version: ' . ($createVersionResponse['error'] ?? 'Unknown error')];
            }

            $versionPath = $createVersionResponse['data']['path'] ?? null;
            $versionId   = $createVersionResponse['data']['containerVersionId'] ?? null;

            if ($versionPath) {
                $this->makeApiCall('POST', "/{$versionPath}/publish", $accessToken);
            }

            return ['success' => true, 'version_id' => $versionId, 'published_at' => now()];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verify that the GTM snippet is present on the customer's website.
     * Uses GTMDetectionService rather than API access.
     */
    public function verifySnippetInstalled(Customer $customer): array
    {
        try {
            if (!$customer->gtm_container_id || !$customer->website) {
                return ['success' => false, 'error' => 'Missing container ID or website URL'];
            }

            $htmlContent = null;

            try {
                $htmlContent = \Spatie\Browsershot\Browsershot::url($customer->website)
                    ->setNodeBinary(config('browsershot.node_binary_path'))
                    ->addChromiumArguments(config('browsershot.chrome_args', []))
                    ->timeout(30)
                    ->waitUntilNetworkIdle()
                    ->bodyHtml();
            } catch (\Exception $e) {
                Log::warning('GTMContainerService: Browsershot failed, falling back to HTTP', ['error' => $e->getMessage()]);
                $htmlContent = @file_get_contents($customer->website);
            }

            if (!$htmlContent) {
                return ['success' => false, 'error' => 'Could not fetch website content'];
            }

            $detectionService = new GTMDetectionService();
            $detected = $detectionService->detectGTMContainer($htmlContent);

            $installed = $detected === $customer->gtm_container_id;

            if ($installed) {
                $customer->update([
                    'gtm_installed'     => true,
                    'gtm_last_verified' => now(),
                ]);
            }

            return [
                'success'   => true,
                'installed' => $installed,
                'detected'  => $detected,
                'expected'  => $customer->gtm_container_id,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Add a Facebook Lead event tag that fires on form submission.
     * Requires the pixel base code tag to already be in the container.
     */
    public function addFacebookLeadEventTag(Customer $customer, string $pixelId): array
    {
        try {
            if (!$customer->gtm_container_id || !$customer->gtm_account_id || !$customer->gtm_workspace_id) {
                return ['success' => false, 'error' => 'Customer does not have a provisioned GTM container'];
            }

            $accessToken = $this->getPlatformAccessToken();
            if (!$accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            $workspacePath = "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}/workspaces/{$customer->gtm_workspace_id}";

            // Create a form submission trigger first
            $triggerResult = $this->makeApiCall('POST', "/{$workspacePath}/triggers", $accessToken, [
                'name' => 'Spectra — Form Submit',
                'type' => 'formSubmission',
                'waitForTags' => ['type' => 'boolean', 'key' => 'waitForTags', 'value' => 'true'],
            ]);

            $triggerId = $triggerResult['data']['triggerId'] ?? null;

            $script = "<script>fbq('track','Lead');</script>";

            $tagData = [
                'name' => 'Spectra — Meta Pixel Lead Event',
                'type' => 'html',
                'parameter' => [
                    ['key' => 'html',                 'type' => 'template', 'value' => $script],
                    ['key' => 'supportDocumentWrite', 'type' => 'boolean',  'value' => 'false'],
                ],
            ];

            if ($triggerId) {
                $tagData['firingTriggerId'] = [$triggerId];
            }

            $response = $this->makeApiCall('POST', "/{$workspacePath}/tags", $accessToken, $tagData);

            if (!$response['success']) {
                return ['success' => false, 'error' => 'Failed to create Meta Lead event tag: ' . ($response['error'] ?? 'Unknown')];
            }

            return ['success' => true, 'tag_id' => $response['data']['tagId'] ?? null];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Add a Microsoft UET Goal event tag that fires on form submission.
     */
    public function addMicrosoftLeadEventTag(Customer $customer, string $uetTagId): array
    {
        try {
            if (!$customer->gtm_container_id || !$customer->gtm_account_id || !$customer->gtm_workspace_id) {
                return ['success' => false, 'error' => 'Customer does not have a provisioned GTM container'];
            }

            $accessToken = $this->getPlatformAccessToken();
            if (!$accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            $workspacePath = "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}/workspaces/{$customer->gtm_workspace_id}";

            // Reuse or recreate the form submit trigger
            $triggerResult = $this->makeApiCall('POST', "/{$workspacePath}/triggers", $accessToken, [
                'name' => 'Spectra — Form Submit (MS)',
                'type' => 'formSubmission',
            ]);

            $triggerId = $triggerResult['data']['triggerId'] ?? null;

            $script = "<script>window.uetq=window.uetq||[];window.uetq.push('event','submit_lead_form',{});</script>";

            $tagData = [
                'name' => 'Spectra — Microsoft UET Lead Event',
                'type' => 'html',
                'parameter' => [
                    ['key' => 'html',                 'type' => 'template', 'value' => $script],
                    ['key' => 'supportDocumentWrite', 'type' => 'boolean',  'value' => 'false'],
                ],
            ];

            if ($triggerId) {
                $tagData['firingTriggerId'] = [$triggerId];
            }

            $response = $this->makeApiCall('POST', "/{$workspacePath}/tags", $accessToken, $tagData);

            if (!$response['success']) {
                return ['success' => false, 'error' => 'Failed to create Microsoft Lead event tag: ' . ($response['error'] ?? 'Unknown')];
            }

            return ['success' => true, 'tag_id' => $response['data']['tagId'] ?? null];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function isValidContainerId(string $containerId): bool
    {
        return preg_match('/^(GTM|GT)-[A-Z0-9]+$/', $containerId) === 1;
    }

    private function makeApiCall(string $method, string $endpoint, string $accessToken, array $data = []): array
    {
        $attempt   = 0;
        $lastError = null;

        while ($attempt < $this->maxRetries) {
            try {
                $url      = $this->baseUrl . $endpoint;
                $request  = Http::withToken($accessToken)->timeout(30);

                $response = match ($method) {
                    'GET'    => $request->get($url),
                    'POST'   => $request->post($url, $data),
                    'PUT'    => $request->put($url, $data),
                    'DELETE' => $request->delete($url),
                    default  => null,
                };

                if ($response === null) {
                    return ['success' => false, 'error' => 'Invalid HTTP method: ' . $method];
                }

                if ($response->successful()) {
                    return ['success' => true, 'data' => $response->json()];
                }

                if ($response->status() === 429) {
                    $attempt++;
                    if ($attempt < $this->maxRetries) {
                        usleep($this->retryDelayMs * pow(2, $attempt - 1) * 1000);
                        continue;
                    }
                }

                $lastError = $response->json()['error']['message'] ?? $response->body();
                return ['success' => false, 'error' => $lastError, 'status_code' => $response->status()];

            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $attempt++;
                if ($attempt < $this->maxRetries) {
                    usleep($this->retryDelayMs * pow(2, $attempt - 1) * 1000);
                }
            }
        }

        return ['success' => false, 'error' => $lastError ?? 'Unknown error after retries'];
    }

    private function buildTriggerConfiguration(string $triggerName, string $triggerType, array $config): array
    {
        $triggerData = ['name' => $triggerName];

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
                $triggerData['customEventFilter'] = [[
                    'type' => 'equals',
                    'parameter' => [
                        ['type' => 'template', 'key' => 'arg0', 'value' => '{{_event}}'],
                        ['type' => 'template', 'key' => 'arg1', 'value' => $config['event_name'] ?? 'purchase'],
                    ],
                ]];
                break;

            case 'form_submit':
                $triggerData['type'] = 'formSubmission';
                $triggerData['waitForTags'] = ['enabled' => true, 'timeout' => 2000];
                break;

            case 'scroll_depth':
                $triggerData['type'] = 'scrollDepth';
                $triggerData['percentageScroll'] = [
                    'enabled'    => true,
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

