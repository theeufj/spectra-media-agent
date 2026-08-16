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
            $clientId = config('services.google.client_id');
            $clientSecret = config('services.google.client_secret');

            if (! $refreshToken || ! $clientId || ! $clientSecret) {
                Log::warning('GTMContainerService: Missing GTM platform credentials (GTM_PLATFORM_REFRESH_TOKEN, GOOGLE_OAUTH_CLIENT_ID, or GOOGLE_OAUTH_CLIENT_SECRET)');

                return null;
            }

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);

            if (! $response->successful()) {
                Log::error('GTMContainerService: Failed to exchange refresh token', [
                    'status' => $response->status(),
                    'error' => $response->json()['error'] ?? $response->body(),
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

            if (! $accountId) {
                return ['success' => false, 'error' => 'GTM_PLATFORM_ACCOUNT_ID is not configured'];
            }

            $accessToken = $this->getPlatformAccessToken();
            if (! $accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            // Reuse an existing container rather than creating a second one.
            //
            // This method previously POSTed unconditionally. SetupConversionTracking
            // retries three times, and its own guard is on conversion_action_id —
            // which is set *after* the container. So any failure downstream of
            // provisioning (a Google Ads conversion action error, a Meta pixel
            // error) meant the next attempt minted another container, up to three
            // per customer, each with its own snippet the customer would have to
            // install for tracking to work.
            if ($customer->gtm_container_id && ! empty($customer->gtm_config['container_path'])) {
                Log::info('GTMContainerService: Container already provisioned, reusing', [
                    'customer_id' => $customer->id,
                    'container_id' => $customer->gtm_container_id,
                ]);

                return [
                    'success' => true,
                    'container_id' => $customer->gtm_container_id,
                    'container_path' => $customer->gtm_config['container_path'],
                    'workspace_id' => $customer->gtm_workspace_id,
                    'reused' => true,
                ];
            }

            $containerName = $customer->name.' — Site to Spend';
            $response = $this->makeApiCall('POST', "/accounts/{$accountId}/containers", $accessToken, [
                'name' => $containerName,
                'usageContext' => ['WEB'],
            ]);

            if (! $response['success']) {
                return ['success' => false, 'error' => 'Failed to create GTM container: '.($response['error'] ?? 'Unknown error')];
            }

            $containerId = $response['data']['publicId'] ?? null;  // GTM-XXXXXXX
            $containerPath = $response['data']['path'] ?? null;       // accounts/.../containers/...

            if (! $containerId) {
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
                if (! $workspaceId && ! empty($workspacesResponse['data']['workspace'])) {
                    $workspaceId = $workspacesResponse['data']['workspace'][0]['workspaceId'];
                }
            }

            $customer->update([
                'gtm_container_id' => $containerId,
                'gtm_account_id' => $accountId,
                'gtm_workspace_id' => $workspaceId,
                'gtm_installed' => false,
                'gtm_last_verified' => null,
                'gtm_config' => [
                    'container_id' => $containerId,
                    'container_path' => $containerPath,
                    'container_name' => $containerName,
                    'account_id' => $accountId,
                    'workspace_id' => $workspaceId,
                    'provisioned_at' => now()->toIso8601String(),
                ],
            ]);

            Log::info('GTMContainerService: Container provisioned', [
                'customer_id' => $customer->id,
                'container_id' => $containerId,
                'account_id' => $accountId,
            ]);

            return [
                'success' => true,
                'container_id' => $containerId,
                'workspace_id' => $workspaceId,
            ];
        } catch (\Exception $e) {
            Log::error('GTMContainerService: Error provisioning container', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
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
     *
     * Always attaches a form-submit trigger (creating it if needed). If a tag
     * with the same name already exists but has no trigger, patches it in place.
     */
    public function addConversionTag(Customer $customer, string $tagName, string $conversionId, array $config = []): array
    {
        try {
            if (! $customer->gtm_container_id || ! $customer->gtm_account_id || ! $customer->gtm_workspace_id) {
                return ['success' => false, 'error' => 'Customer does not have a provisioned GTM container'];
            }

            $accessToken = $this->getPlatformAccessToken();
            if (! $accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            $workspacePath = $this->getWorkspacePath($customer);

            // Resolve trigger: caller-supplied > existing form-submit trigger > newly created one
            // Conversions happen two ways on most sites: a form is submitted in
            // place, or the visitor lands on a thank-you page. Firing only on
            // form submission misses every site that redirects after submit,
            // which is the more common pattern — so both are attached unless the
            // caller names a specific trigger.
            $explicitTrigger = $config['firing_trigger_id'] ?? null;

            $triggerIds = $explicitTrigger
                ? [$explicitTrigger]
                : array_values(array_filter([
                    $this->getOrCreateFormSubmitTrigger($workspacePath, $accessToken),
                    $this->getOrCreateThankYouPageTrigger($workspacePath, $accessToken),
                ]));

            $triggerId = $triggerIds[0] ?? null;

            // awct tag type requires a bare numeric ID — strip the "AW-" prefix if present
            $numericConversionId = preg_replace('/^AW-/i', '', $conversionId);

            $tagData = [
                'name' => $tagName,
                'type' => 'awct',
                'parameter' => [
                    ['key' => 'conversionId',    'type' => 'template', 'value' => $numericConversionId],
                    ['key' => 'conversionLabel', 'type' => 'template', 'value' => $config['conversion_label'] ?? ''],
                ],
                'firingTriggerId' => $triggerIds,
            ];

            $response = $this->makeApiCall('POST', "/{$workspacePath}/tags", $accessToken, $tagData);

            if (! $response['success']) {
                // Tag already exists — find it and patch in the trigger if it's missing
                if (str_contains($response['error'] ?? '', 'duplicate name')) {
                    $existing = $this->findTagByName($workspacePath, $tagName, $accessToken);
                    if ($existing) {
                        $tagId = $existing['tagId'];
                        if ($triggerIds && empty($existing['firingTriggerId'])) {
                            $existing['firingTriggerId'] = $triggerIds;
                            $this->makeApiCall('PUT', "/{$workspacePath}/tags/{$tagId}", $accessToken, $existing);
                        }

                        return ['success' => true, 'tag_id' => $tagId, 'tag_name' => $tagName, 'existing' => true];
                    }
                }

                return ['success' => false, 'error' => 'Failed to create tag: '.($response['error'] ?? 'Unknown error')];
            }

            return [
                'success' => true,
                'tag_id' => $response['data']['tagId'] ?? null,
                'tag_name' => $tagName,
                'trigger_id' => $triggerId,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Return the trigger ID of "Spectra — Form Submit", creating it if absent.
     */
    private function getOrCreateFormSubmitTrigger(string $workspacePath, string $accessToken): ?string
    {
        // Reuse an existing trigger rather than creating duplicates
        $listResponse = $this->makeApiCall('GET', "/{$workspacePath}/triggers", $accessToken);
        if ($listResponse['success']) {
            foreach ($listResponse['data']['trigger'] ?? [] as $trigger) {
                if ($trigger['name'] === 'Spectra — Form Submit') {
                    return $trigger['triggerId'];
                }
            }
        }

        $response = $this->makeApiCall('POST', "/{$workspacePath}/triggers", $accessToken, [
            'name' => 'Spectra — Form Submit',
            'type' => 'formSubmission',
            'waitForTags' => ['type' => 'boolean', 'key' => 'waitForTags',     'value' => 'true'],
            'checkValidation' => ['type' => 'boolean', 'key' => 'checkValidation', 'value' => 'false'],
        ]);

        return $response['data']['triggerId'] ?? null;
    }

    /**
     * Find a tag in the workspace by name. Returns the tag array or null.
     */
    /**
     * A page-view trigger matching the usual post-conversion destinations.
     *
     * Form submission alone only catches sites that submit in place. Anything
     * that redirects to a confirmation page — most lead-gen and every checkout —
     * converts without ever firing a formSubmission event.
     *
     * Deliberately never matches on "/" alone: a pattern loose enough to hit the
     * home page would record every visit as a conversion, which is exactly the
     * All Pages mistake that had to be undone on the Spectra container.
     */
    private function getOrCreateThankYouPageTrigger(string $workspacePath, string $accessToken): ?string
    {
        $name = 'Spectra — Conversion Page View';

        $listResponse = $this->makeApiCall('GET', "/{$workspacePath}/triggers", $accessToken);
        if ($listResponse['success']) {
            foreach ($listResponse['data']['trigger'] ?? [] as $trigger) {
                if (($trigger['name'] ?? '') === $name) {
                    return $trigger['triggerId'];
                }
            }
        }

        $response = $this->makeApiCall('POST', "/{$workspacePath}/triggers", $accessToken, [
            'name' => $name,
            'type' => 'pageview',
            'filter' => [[
                'type' => 'matchRegex',
                'parameter' => [
                    ['type' => 'template', 'key' => 'arg0', 'value' => '{{Page URL}}'],
                    ['type' => 'template', 'key' => 'arg1', 'value' => '(thank[-_]?you|/success|/confirmation|order[-_]?complete|/welcome)'],
                ],
            ]],
        ]);

        return $response['data']['triggerId'] ?? null;
    }

    private function findTagByName(string $workspacePath, string $tagName, string $accessToken): ?array
    {
        $response = $this->makeApiCall('GET', "/{$workspacePath}/tags", $accessToken);
        if (! $response['success']) {
            return null;
        }
        foreach ($response['data']['tag'] ?? [] as $tag) {
            if ($tag['name'] === $tagName) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * Add a Meta Pixel base code tag to the customer's GTM container.
     * Fires on all pages. Creates the pixel with fbq('init') + fbq('track', 'PageView').
     */
    public function addFacebookPixelTag(Customer $customer, string $pixelId): array
    {
        try {
            if (! $customer->gtm_container_id || ! $customer->gtm_account_id || ! $customer->gtm_workspace_id) {
                return ['success' => false, 'error' => 'Customer does not have a provisioned GTM container'];
            }

            $accessToken = $this->getPlatformAccessToken();
            if (! $accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            $workspacePath = $this->getWorkspacePath($customer);

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

            if (! $response['success']) {
                return ['success' => false, 'error' => 'Failed to create Meta Pixel tag: '.($response['error'] ?? 'Unknown error')];
            }

            return ['success' => true, 'tag_id' => $response['data']['tagId'] ?? null];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fire a Meta conversion event when a visitor converts.
     *
     * The base pixel tag above only tracks PageView, so until now Meta learned
     * that people visited a customer's site and nothing else. A campaign
     * optimising for conversions with no conversion events does not
     * underperform slightly — Meta's delivery is almost entirely signal-driven,
     * so it optimises for whatever it can measure, which was page views.
     *
     * Fires on the same triggers as the Google Ads conversion tag: forms
     * submitted in place, and thank-you pages for sites that redirect.
     *
     * @param  array{event_name?: string, value?: float, currency?: string, firing_trigger_id?: string}  $config
     * @return array{success: bool, tag_id?: string|null, tag_name?: string, existing?: bool, error?: string}
     */
    public function addFacebookConversionTag(Customer $customer, string $pixelId, array $config = []): array
    {
        try {
            if (! $customer->gtm_container_id || ! $customer->gtm_account_id || ! $customer->gtm_workspace_id) {
                return ['success' => false, 'error' => 'Customer does not have a provisioned GTM container'];
            }

            $accessToken = $this->getPlatformAccessToken();

            if (! $accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            $workspacePath = $this->getWorkspacePath($customer);

            // Lead is the right default: most customers here are service
            // businesses whose conversion is an enquiry, not a checkout.
            $eventName = $config['event_name'] ?? 'Lead';
            $tagName = 'Spectra — Meta Pixel '.$eventName;

            $explicitTrigger = $config['firing_trigger_id'] ?? null;

            $triggerIds = $explicitTrigger
                ? [$explicitTrigger]
                : array_values(array_filter([
                    $this->getOrCreateFormSubmitTrigger($workspacePath, $accessToken),
                    $this->getOrCreateThankYouPageTrigger($workspacePath, $accessToken),
                ]));

            $script = $this->getFacebookConversionScript($eventName, $config);

            $tagData = [
                'name' => $tagName,
                'type' => 'html',
                'parameter' => [
                    ['key' => 'html', 'type' => 'template', 'value' => $script],
                    ['key' => 'supportDocumentWrite', 'type' => 'boolean', 'value' => 'false'],
                ],
                'firingTriggerId' => $triggerIds,
            ];

            $response = $this->makeApiCall('POST', "/{$workspacePath}/tags", $accessToken, $tagData);

            if (! $response['success']) {
                // Same recovery as the Google tag: a container that already has
                // the tag but no trigger fires nothing, and that is the state a
                // half-finished earlier setup leaves behind.
                if (str_contains($response['error'] ?? '', 'duplicate name')) {
                    $existing = $this->findTagByName($workspacePath, $tagName, $accessToken);

                    if ($existing) {
                        $tagId = $existing['tagId'];

                        if ($triggerIds && empty($existing['firingTriggerId'])) {
                            $existing['firingTriggerId'] = $triggerIds;
                            $this->makeApiCall('PUT', "/{$workspacePath}/tags/{$tagId}", $accessToken, $existing);
                        }

                        return ['success' => true, 'tag_id' => $tagId, 'tag_name' => $tagName, 'existing' => true];
                    }
                }

                return ['success' => false, 'error' => 'Failed to create Meta conversion tag: '.($response['error'] ?? 'Unknown error')];
            }

            return [
                'success' => true,
                'tag_id' => $response['data']['tagId'] ?? null,
                'tag_name' => $tagName,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * The browser-side conversion event.
     *
     * Carries an eventID, which is what lets Meta recognise a browser event and
     * a Conversions API event as the same conversion rather than two. Nothing
     * currently sends the website conversion server-side as well, but offline
     * uploads already go through the CAPI for these pixels, and the day a
     * server-side duplicate of this event appears, an event without an ID is
     * counted twice — inflating reported conversions and feeding the
     * optimisation agents doubled data.
     *
     * The ID is also pushed to the dataLayer so a server-side sender can pick up
     * the same value rather than inventing its own.
     *
     * @param  array{value?: float, currency?: string}  $config
     */
    private function getFacebookConversionScript(string $eventName, array $config = []): string
    {
        $payload = [];

        if (isset($config['value'])) {
            $payload[] = 'value: '.(float) $config['value'];
            $payload[] = "currency: '".($config['currency'] ?? 'AUD')."'";
        }

        $customData = $payload ? '{'.implode(', ', $payload).'}' : '{}';

        return <<<JAVASCRIPT
<script>
(function () {
  // The base pixel fires on All Pages, so fbq exists by the time a visitor can
  // submit anything. Guard anyway: a customer who removes the base tag should
  // lose conversion tracking, not get a JavaScript error on every submit.
  if (typeof fbq !== 'function') { return; }

  var eventId = '{$eventName}.' + Date.now() + '.' + Math.random().toString(36).slice(2, 10);

  window.dataLayer = window.dataLayer || [];
  window.dataLayer.push({ event: 'spectra_meta_conversion', spectraMetaEventId: eventId, spectraMetaEventName: '{$eventName}' });

  fbq('track', '{$eventName}', {$customData}, { eventID: eventId });
})();
</script>
JAVASCRIPT;
    }

    /**
     * Add a Microsoft UET base code tag to the customer's GTM container.
     * Fires on all pages.
     */
    public function addMicrosoftUetTag(Customer $customer, string $uetTagId): array
    {
        try {
            if (! $customer->gtm_container_id || ! $customer->gtm_account_id || ! $customer->gtm_workspace_id) {
                return ['success' => false, 'error' => 'Customer does not have a provisioned GTM container'];
            }

            $accessToken = $this->getPlatformAccessToken();
            if (! $accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            $workspacePath = $this->getWorkspacePath($customer);

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

            if (! $response['success']) {
                return ['success' => false, 'error' => 'Failed to create Microsoft UET tag: '.($response['error'] ?? 'Unknown error')];
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
            if (! $customer->gtm_container_id || ! $customer->gtm_account_id || ! $customer->gtm_workspace_id) {
                return ['success' => false, 'error' => 'Customer does not have a provisioned GTM container'];
            }

            $accessToken = $this->getPlatformAccessToken();
            if (! $accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            $workspacePath = $this->getWorkspacePath($customer);
            $triggerData = $this->buildTriggerConfiguration($triggerName, $triggerType, $config);
            $response = $this->makeApiCall('POST', "/{$workspacePath}/triggers", $accessToken, $triggerData);

            if (! $response['success']) {
                return ['success' => false, 'error' => 'Failed to create trigger: '.($response['error'] ?? 'Unknown error')];
            }

            return [
                'success' => true,
                'trigger_id' => $response['data']['triggerId'] ?? null,
                'trigger_type' => $triggerType,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Publish the current workspace as a new live version.
     * Handles "already submitted" by finding the pending version and publishing it.
     */
    public function publishContainer(Customer $customer, string $notes = ''): array
    {
        try {
            if (! $customer->gtm_container_id || ! $customer->gtm_account_id || ! $customer->gtm_workspace_id) {
                return ['success' => false, 'error' => 'Customer does not have a provisioned GTM container'];
            }

            $accessToken = $this->getPlatformAccessToken();
            if (! $accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            $workspacePath = $this->getWorkspacePath($customer);
            $containerPath = $customer->gtm_config['container_path']
                ?? "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}";

            // GTM v2 API uses RPC-style action suffixes (e.g. :create_version, :publish)
            $createVersionResponse = $this->makeApiCall('POST', "/{$workspacePath}:create_version", $accessToken, [
                'name' => $notes ?: 'Published by Site to Spend — '.now()->toDateTimeString(),
                'notes' => $notes,
            ]);

            if (! $createVersionResponse['success']) {
                // Workspace was already submitted — a version was created but not yet published.
                // Find the latest version and publish it directly.
                if (str_contains($createVersionResponse['error'] ?? '', 'already submitted')) {
                    return $this->publishLatestVersion($containerPath, $accessToken);
                }

                return ['success' => false, 'error' => 'Failed to create version: '.($createVersionResponse['error'] ?? 'Unknown error')];
            }

            $versionPath = $createVersionResponse['data']['containerVersion']['path']
                ?? $createVersionResponse['data']['path']
                ?? null;
            $versionId = $createVersionResponse['data']['containerVersion']['containerVersionId']
                ?? $createVersionResponse['data']['containerVersionId']
                ?? null;

            if (! $versionPath) {
                return ['success' => false, 'error' => 'Version created but the API returned no path to publish'];
            }

            // The publish response was previously discarded and success reported
            // regardless, so a version that was created but never went live read
            // as a successful publish.
            $publishResponse = $this->makeApiCall('POST', "/{$versionPath}:publish", $accessToken);

            if (! $publishResponse['success']) {
                return ['success' => false, 'error' => 'Version '.$versionId.' created but publish failed: '.($publishResponse['error'] ?? 'Unknown error')];
            }

            return ['success' => true, 'version_id' => $versionId, 'published_at' => now()];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List container versions and publish the most recent one.
     * Used when the workspace is already in "submitted" state.
     */
    private function publishLatestVersion(string $containerPath, string $accessToken): array
    {
        // version_headers, not versions. GTM v2 has no /versions collection —
        // it 404s — and the parsing below already expects the header shape this
        // endpoint returns.
        $versionsResponse = $this->makeApiCall('GET', "/{$containerPath}/version_headers", $accessToken);
        if (! $versionsResponse['success']) {
            return ['success' => false, 'error' => 'Workspace already submitted and could not list versions: '.($versionsResponse['error'] ?? '')];
        }

        $headers = $versionsResponse['data']['containerVersionHeader'] ?? [];
        // Filter out deleted versions and sort descending by numeric version ID
        $headers = array_filter($headers, fn ($v) => empty($v['deleted']));
        usort($headers, fn ($a, $b) => (int) $b['containerVersionId'] - (int) $a['containerVersionId']);
        $latest = reset($headers);

        if (! $latest) {
            return ['success' => false, 'error' => 'Workspace already submitted but no publishable version found'];
        }

        $versionPath = $latest['path'];
        $versionId = $latest['containerVersionId'];

        $publishResponse = $this->makeApiCall('POST', "/{$versionPath}:publish", $accessToken);
        if (! $publishResponse['success']) {
            return ['success' => false, 'error' => 'Found submitted version but publish failed: '.($publishResponse['error'] ?? '')];
        }

        return ['success' => true, 'version_id' => $versionId, 'published_at' => now()];
    }

    /**
     * Verify that the GTM snippet is present on the customer's website.
     * Uses GTMDetectionService rather than API access.
     */
    public function verifySnippetInstalled(Customer $customer): array
    {
        try {
            if (! $customer->gtm_container_id || ! $customer->website) {
                return ['success' => false, 'error' => 'Missing container ID or website URL'];
            }

            $htmlContent = null;

            try {
                // Wait only for domcontentloaded — the GTM snippet is in the initial
                // HTML. waitUntilNetworkIdle (networkidle0) never completes on a page
                // with live analytics/ads traffic and burns the full timeout.
                $htmlContent = \Spatie\Browsershot\Browsershot::url($customer->website)
                    ->setNodeBinary(config('browsershot.node_binary_path'))
                    ->addChromiumArguments(config('browsershot.chrome_args', []))
                    ->timeout(20)
                    ->setOption('waitUntil', 'domcontentloaded')
                    ->bodyHtml();
            } catch (\Exception $e) {
                Log::warning('GTMContainerService: Browsershot failed, falling back to HTTP', ['error' => $e->getMessage()]);
                $htmlContent = @file_get_contents($customer->website);
            }

            if (! $htmlContent) {
                return ['success' => false, 'error' => 'Could not fetch website content'];
            }

            $detectionService = new GTMDetectionService;
            // Check whether OUR container is among all containers on the page.
            // The site may run several GTM containers; a strict equality against
            // the first-detected one would fail even when ours is installed.
            $allDetected = $detectionService->detectAllContainers($htmlContent);
            $installed = in_array($customer->gtm_container_id, $allDetected, true);

            if ($installed) {
                $customer->update([
                    'gtm_installed' => true,
                    'gtm_last_verified' => now(),
                ]);
            }

            return [
                'success' => true,
                'installed' => $installed,
                'detected' => $allDetected,
                'expected' => $customer->gtm_container_id,
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
            if (! $customer->gtm_container_id || ! $customer->gtm_account_id || ! $customer->gtm_workspace_id) {
                return ['success' => false, 'error' => 'Customer does not have a provisioned GTM container'];
            }

            $accessToken = $this->getPlatformAccessToken();
            if (! $accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            $workspacePath = $this->getWorkspacePath($customer);

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

            if (! $response['success']) {
                return ['success' => false, 'error' => 'Failed to create Meta Lead event tag: '.($response['error'] ?? 'Unknown')];
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
            if (! $customer->gtm_container_id || ! $customer->gtm_account_id || ! $customer->gtm_workspace_id) {
                return ['success' => false, 'error' => 'Customer does not have a provisioned GTM container'];
            }

            $accessToken = $this->getPlatformAccessToken();
            if (! $accessToken) {
                return ['success' => false, 'error' => 'Unable to authenticate with GTM platform account'];
            }

            $workspacePath = $this->getWorkspacePath($customer);

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

            if (! $response['success']) {
                return ['success' => false, 'error' => 'Failed to create Microsoft Lead event tag: '.($response['error'] ?? 'Unknown')];
            }

            return ['success' => true, 'tag_id' => $response['data']['tagId'] ?? null];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build the workspace path for API calls.
     *
     * Uses the numeric container path from gtm_config if available, because the GTM API
     * requires a numeric container ID in the URL — the public GTM-XXXXXXX format only
     * works in the install snippet, not in REST calls.
     */
    private function getWorkspacePath(Customer $customer): string
    {
        $containerPath = $customer->gtm_config['container_path']
            ?? "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}";

        return "{$containerPath}/workspaces/{$customer->gtm_workspace_id}";
    }

    private function isValidContainerId(string $containerId): bool
    {
        return preg_match('/^(GTM|GT)-[A-Z0-9]+$/', $containerId) === 1;
    }

    private function makeApiCall(string $method, string $endpoint, string $accessToken, array $data = []): array
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->maxRetries) {
            try {
                $url = $this->baseUrl.$endpoint;
                $request = Http::withToken($accessToken)->timeout(30);

                // An empty $data array json-encodes to [], and GTM's RPC-style
                // endpoints (:create_version, :publish) reject that with "Root
                // element must be a message" — they want an object. Sending {}
                // explicitly is the difference between a version publishing and
                // sitting unpublished forever.
                $response = match ($method) {
                    'GET' => $request->get($url),
                    'POST' => $data === []
                        ? $request->withBody('{}', 'application/json')->post($url)
                        : $request->post($url, $data),
                    'PUT' => $data === []
                        ? $request->withBody('{}', 'application/json')->put($url)
                        : $request->put($url, $data),
                    'DELETE' => $request->delete($url),
                    default => null,
                };

                if ($response === null) {
                    return ['success' => false, 'error' => 'Invalid HTTP method: '.$method];
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
