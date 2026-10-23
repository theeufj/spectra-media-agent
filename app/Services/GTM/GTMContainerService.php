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
        } catch (\Throwable $e) {
            report($e);
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
    public function provisionContainerForCustomer(Customer $customer, bool $customerScoped = false, ?callable $authorize = null): array
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
            if ($customer->gtm_container_id && ! empty($customer->gtm_config['container_path'])
                && (! $customerScoped || ($customer->gtm_config['customer_uuid'] ?? null) === $customer->uuid)) {
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

            $containerName = $customerScoped ? 'Site to Spend '.$customer->uuid : $customer->name.' — Site to Spend';
            if ($authorize) {
                $authorize();
            }
            $response = $this->makeApiCall('POST', "/accounts/{$accountId}/containers", $accessToken, [
                'name' => $containerName,
                'usageContext' => ['WEB'],
            ]);

            if (! $response['success']) {
                // A container with this name already exists — a previous
                // customer with the same business name (duplicate onboarding,
                // deleted-and-recreated test accounts). Adopt it rather than
                // failing the whole conversion setup: the alternative was
                // three noisy retries and a customer with no tracking.
                if (str_contains($response['error'] ?? '', 'duplicate name')) {
                    $existing = $this->findContainerByName($accountId, $containerName, $accessToken);

                    if ($existing) {
                        Log::info('GTMContainerService: adopted existing container with the same name', [
                            'customer_id' => $customer->id,
                            'container_id' => $existing['publicId'] ?? null,
                        ]);
                        $response = ['success' => true, 'data' => $existing];
                    }
                }

                if (! $response['success']) {
                    return ['success' => false, 'error' => 'Failed to create GTM container: '.($response['error'] ?? 'Unknown error')];
                }
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

            if ($authorize) {
                $authorize();
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
                    ...($customerScoped ? ['customer_uuid' => $customer->uuid] : []),
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
        } catch (\Throwable $e) {
            report($e);
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
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Upsert one owned campaign tag with exactly the trigger its owner approved. */
    public function configureReviewedConversionTag(Customer $customer, string $strategyUuid, array $goal, string $conversionId, string $label, ?callable $authorize = null): array
    {
        $accountId = (string) config('services.gtm.platform_account_id');
        $containerPath = $customer->gtm_config['container_path'] ?? '';
        if (! $accountId || (string) $customer->gtm_account_id !== $accountId
            || ! preg_match('#^accounts/'.preg_quote($accountId, '#').'/containers/\d+$#', $containerPath)
            || ! $customer->gtm_workspace_id || ! preg_match('/^AW-\d+$/', $conversionId) || ! preg_match('/^[A-Za-z0-9_-]+$/', $label)
            || ! \Illuminate\Support\Str::isUuid($strategyUuid)) {
            return ['success' => false, 'error' => 'A platform-owned customer tag container and valid conversion tag are required.'];
        }
        $token = $this->getPlatformAccessToken();
        if (! $token) {
            return ['success' => false, 'error' => 'Managed tag authentication is unavailable.'];
        }
        $host = parse_url($customer->website ?? '', PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return ['success' => false, 'error' => 'A business website is required.'];
        }
        $condition = fn (string $variable, string $value) => ['type' => 'equals', 'parameter' => [
            ['type' => 'template', 'key' => 'arg0', 'value' => '{{'.$variable.'}}'],
            ['type' => 'template', 'key' => 'arg1', 'value' => $value],
        ]];
        $purchaseVariables = [];
        if (($goal['objective'] ?? null) === 'purchase') {
            foreach (['transaction_id', 'value', 'currency'] as $field) {
                $purchaseVariables[] = ['name' => 'Site to Spend '.$strategyUuid.' purchase '.$field, 'type' => 'v', 'parameter' => [
                    ['key' => 'name', 'type' => 'template', 'value' => 'ecommerce.'.$field],
                    ['key' => 'dataLayerVersion', 'type' => 'integer', 'value' => '2'],
                ]];
            }
        }
        $trigger = ['name' => 'Site to Spend '.$strategyUuid.' '.substr(hash('sha256', json_encode([$goal, $purchaseVariables])), 0, 16),
            'filter' => [$condition('Page Hostname', $host)]];
        if ($purchaseVariables !== []) {
            foreach (['transaction_id' => '^[A-Za-z0-9][A-Za-z0-9:_.-]{0,127}$', 'currency' => '^[A-Z]{3}$', 'value' => '^\\d+(\\.\\d{1,2})?$'] as $field => $pattern) {
                $check = $condition('Site to Spend '.$strategyUuid.' purchase '.$field, $pattern);
                $check['type'] = 'matchRegex';
                $trigger['filter'][] = $check;
            }
            $check = $condition('Site to Spend '.$strategyUuid.' purchase value', '0');
            $check['type'] = 'greater';
            $trigger['filter'][] = $check;
        }
        if (($goal['trigger'] ?? '') === 'thank_you_url') {
            $url = parse_url($goal['thank_you_url'] ?? '');
            if (! $url || ($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? '') !== $host || in_array($url['path'] ?? '/', ['', '/'], true)) {
                return ['success' => false, 'error' => 'A precise same-site conversion page is required.'];
            }
            $trigger['type'] = 'pageview';
            $trigger['filter'][] = $condition('Page URL', $goal['thank_you_url']);
        } elseif (($goal['trigger'] ?? '') === 'event' && preg_match('/^[A-Za-z][A-Za-z0-9_]{1,99}$/', $goal['event_name'] ?? '')) {
            $trigger['type'] = 'customEvent';
            $trigger['customEventFilter'] = [$condition('_event', $goal['event_name'])];
        } else {
            return ['success' => false, 'error' => 'A specific conversion trigger is required.'];
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $workspace = $this->getWorkspacePath($customer);
            $listed = $this->makeApiCall('GET', "/{$workspace}/triggers", $token);
            if (! $listed['success'] && $this->workspaceIsUnusable($listed) && $this->rotateWorkspace($customer, $token, $authorize)) {
                continue;
            }
            if (! $listed['success']) {
                return ['success' => false, 'error' => 'Managed conversion triggers could not be read.'];
            }
            if ($authorize) {
                $authorize();
            }
            $types = $trigger['type'] === 'pageview' ? 'type=pageHostname&type=pageUrl' : 'type=pageHostname&type=event';
            $variables = $this->makeApiCall('POST', "/{$workspace}/built_in_variables?{$types}", $token);
            if (! $variables['success']) {
                return ['success' => false, 'error' => 'The reviewed trigger variables could not be enabled.'];
            }
            if ($purchaseVariables !== [] && ! $this->ensureReviewedVariables($workspace, $token, $purchaseVariables, $authorize)) {
                return ['success' => false, 'error' => 'The purchase amount, currency and transaction variables could not be verified.'];
            }
            $existing = collect($listed['data']['trigger'] ?? [])->firstWhere('name', $trigger['name']);
            if ($existing) {
                foreach (['type', 'filter', 'customEventFilter'] as $key) {
                    if (! $this->reviewedEqual($existing[$key] ?? [], $trigger[$key] ?? [])) {
                        return ['success' => false, 'error' => 'An existing managed trigger differs from the reviewed definition.'];
                    }
                }
                $triggerId = $existing['triggerId'] ?? null;
            } else {
                if ($authorize) {
                    $authorize();
                }
                $created = $this->makeApiCall('POST', "/{$workspace}/triggers", $token, $trigger);
                $triggerId = $created['success'] ? ($created['data']['triggerId'] ?? null) : null;
            }
            if (! $triggerId) {
                return ['success' => false, 'error' => 'The reviewed conversion trigger could not be created.'];
            }
            $name = 'Site to Spend '.$strategyUuid.' conversion';
            $tags = $this->makeApiCall('GET', "/{$workspace}/tags", $token);
            if (! $tags['success']) {
                return ['success' => false, 'error' => 'Managed conversion tags could not be read.'];
            }
            $existingTag = collect($tags['data']['tag'] ?? [])->firstWhere('name', $name);
            $tag = ['name' => $name, 'type' => 'awct', 'parameter' => [
                ['key' => 'conversionId', 'type' => 'template', 'value' => substr($conversionId, 3)],
                ['key' => 'conversionLabel', 'type' => 'template', 'value' => $label],
            ], 'firingTriggerId' => [(string) $triggerId]];
            if ($purchaseVariables !== []) {
                foreach (['orderId' => 'transaction_id', 'conversionValue' => 'value', 'currencyCode' => 'currency'] as $key => $field) {
                    $tag['parameter'][] = ['key' => $key, 'type' => 'template', 'value' => '{{Site to Spend '.$strategyUuid.' purchase '.$field.'}}'];
                }
            }
            $endpoint = "/{$workspace}/tags".($existingTag ? '/'.$existingTag['tagId'] : '');
            if ($authorize) {
                $authorize();
            }
            $written = $this->makeApiCall($existingTag ? 'PUT' : 'POST', $endpoint, $token, $tag);
            if (! $written['success'] && $this->workspaceIsUnusable($written) && $this->rotateWorkspace($customer, $token, $authorize)) {
                continue;
            }
            if (! $written['success']) {
                return ['success' => false, 'error' => 'The reviewed conversion tag could not be saved.'];
            }

            return $this->publishReviewedWorkspace($customer, $workspace, $token, $tag, $trigger + ['triggerId' => (string) $triggerId], $authorize, $purchaseVariables);
        }

        return ['success' => false, 'error' => 'A writable managed tag workspace is required.'];
    }

    /** Remove only this strategy's previously managed measurement when an existing action replaces it. */
    public function retireReviewedConversionTag(Customer $customer, string $strategyUuid, ?callable $authorize = null): array
    {
        $account = (string) config('services.gtm.platform_account_id');
        $container = $customer->gtm_config['container_path'] ?? '';
        if (! $account || (string) $customer->gtm_account_id !== $account
            || ! preg_match('#^accounts/'.preg_quote($account, '#').'/containers/\d+$#', $container)
            || ! \Illuminate\Support\Str::isUuid($strategyUuid)) {
            return ['success' => false, 'error' => 'A platform-owned tag container is required.'];
        }
        $token = $this->getPlatformAccessToken();
        if (! $token) {
            return ['success' => false, 'error' => 'Managed tag authentication is unavailable.'];
        }
        $name = 'Site to Spend '.$strategyUuid.' conversion';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $workspace = $this->getWorkspacePath($customer);
            $tags = $this->makeApiCall('GET', "/{$workspace}/tags", $token);
            if (! $tags['success'] && $this->workspaceIsUnusable($tags) && $this->rotateWorkspace($customer, $token, $authorize)) {
                continue;
            }
            if (! $tags['success']) {
                return ['success' => false, 'error' => 'Existing managed tags could not be checked.'];
            }
            $tag = collect($tags['data']['tag'] ?? [])->firstWhere('name', $name);
            if (! $tag) {
                $live = $this->makeApiCall('GET', "/{$container}/versions:live", $token);
                if (! $live['success']) {
                    return ['success' => false, 'error' => 'The replaced tag could not be checked in the live container.'];
                }
                if (! collect($live['data']['tag'] ?? [])->firstWhere('name', $name)) {
                    return ['success' => true];
                }

                return $this->publishReviewedWorkspace($customer, $workspace, $token, ['name' => $name], null, $authorize);
            }
            if ($authorize) {
                $authorize();
            }
            $deleted = $this->makeApiCall('DELETE', "/{$workspace}/tags/{$tag['tagId']}", $token);
            if (! $deleted['success']) {
                return ['success' => false, 'error' => 'The replaced managed tag could not be retired.'];
            }

            return $this->publishReviewedWorkspace($customer, $workspace, $token, ['name' => $name], null, $authorize);
        }

        return ['success' => false, 'error' => 'A writable managed workspace is required.'];
    }

    /** Publish exactly our reviewed version, then inspect its actual live tag and trigger. */
    private function publishReviewedWorkspace(Customer $customer, string $workspace, string $token, array $tag, ?array $trigger, ?callable $authorize, array $variables = []): array
    {
        if ($authorize) {
            $authorize();
        }
        $created = $this->makeApiCall('POST', "/{$workspace}:create_version", $token, ['name' => 'Site to Spend reviewed goal '.now()->format('Y-m-d His')]);
        $version = $created['data']['containerVersion'] ?? [];
        $path = $version['path'] ?? '';
        $container = $customer->gtm_config['container_path'];
        if (! $created['success'] || ($created['data']['compilerError'] ?? false)
            || ! preg_match('#^'.preg_quote($container, '#').'/versions/\d+$#', $path)
            || ! $this->reviewedVersionMatches($version, $tag, $trigger, $variables)) {
            return ['success' => false, 'error' => 'The reviewed tag version could not be compiled and verified.'];
        }
        if ($authorize) {
            $authorize();
        }
        $published = $this->makeApiCall('POST', "/{$path}:publish", $token);
        if (! $published['success'] || ($published['data']['compilerError'] ?? false)
            || ($published['data']['containerVersion']['path'] ?? null) !== $path) {
            return ['success' => false, 'error' => 'The exact reviewed tag version could not be published.'];
        }
        $live = $this->makeApiCall('GET', "/{$container}/versions:live", $token);
        if (! $live['success'] || ($live['data']['path'] ?? null) !== $path || ! $this->reviewedVersionMatches($live['data'], $tag, $trigger, $variables)) {
            return ['success' => false, 'error' => 'The live tag does not match the reviewed conversion configuration.'];
        }

        return ['success' => true, 'version_path' => $path];
    }

    private function reviewedVersionMatches(array $version, array $tag, ?array $trigger, array $variables = []): bool
    {
        $actualTag = collect($version['tag'] ?? [])->firstWhere('name', $tag['name']);
        if ($trigger === null) {
            return $actualTag === null;
        }
        if (! $actualTag || ($actualTag['type'] ?? null) !== 'awct' || ($actualTag['paused'] ?? false) === true
            || ! $this->reviewedEqual($actualTag['parameter'] ?? [], $tag['parameter'])
            || ! $this->reviewedEqual($actualTag['firingTriggerId'] ?? [], $tag['firingTriggerId'])) {
            return false;
        }
        $actualTrigger = collect($version['trigger'] ?? [])->firstWhere('triggerId', $trigger['triggerId']);
        if (! $actualTrigger) {
            return false;
        }
        foreach (['type', 'filter', 'customEventFilter'] as $key) {
            if (! $this->reviewedEqual($actualTrigger[$key] ?? [], $trigger[$key] ?? [])) {
                return false;
            }
        }
        foreach ($variables as $variable) {
            $actual = collect($version['variable'] ?? [])->firstWhere('name', $variable['name']);
            if (! $actual || ($actual['type'] ?? null) !== $variable['type'] || ! $this->reviewedEqual($actual['parameter'] ?? [], $variable['parameter'])) {
                return false;
            }
        }

        return true;
    }

    private function ensureReviewedVariables(string $workspace, string $token, array $variables, ?callable $authorize): bool
    {
        $listed = $this->makeApiCall('GET', "/{$workspace}/variables", $token);
        if (! $listed['success']) {
            return false;
        }
        foreach ($variables as $definition) {
            $existing = collect($listed['data']['variable'] ?? [])->firstWhere('name', $definition['name']);
            if ($existing) {
                if (($existing['type'] ?? null) !== $definition['type'] || ! $this->reviewedEqual($existing['parameter'] ?? [], $definition['parameter'])) {
                    return false;
                }
            } else {
                if ($authorize) {
                    $authorize();
                }
                $created = $this->makeApiCall('POST', "/{$workspace}/variables", $token, $definition);
                if (! $created['success']) {
                    return false;
                }
            }
        }

        return true;
    }

    private function reviewedEqual(mixed $left, mixed $right): bool
    {
        $canonical = function ($value) use (&$canonical) {
            if (! is_array($value)) {
                return $value;
            }
            $value = array_map($canonical, $value);
            if (array_is_list($value)) {
                usort($value, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
            } else {
                ksort($value);
            }

            return $value;
        };

        return $canonical($left) === $canonical($right);
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

    /**
     * The container the duplicate-name error is complaining about.
     */
    private function findContainerByName(string $accountId, string $containerName, string $accessToken): ?array
    {
        $response = $this->makeApiCall('GET', "/accounts/{$accountId}/containers", $accessToken);

        if (! $response['success']) {
            return null;
        }

        foreach ($response['data']['container'] ?? [] as $container) {
            if (($container['name'] ?? null) === $containerName) {
                return $container;
            }
        }

        return null;
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

            $hostname = $this->customerHostname($customer);
            $guard = $this->hostnameGuard($hostname);

            // The noscript beacon cannot be host-guarded — it is markup, not
            // script — so it is omitted entirely on a shared container rather
            // than fire another brand's pixel for every no-JS visitor.
            $noscript = $hostname === null
                ? '<noscript><img height="1" width="1" style="display:none" '
                    ."src=\"https://www.facebook.com/tr?id={$pixelId}&ev=PageView&noscript=1\"/></noscript>"
                : '';

            $pixelScript = <<<JS
<script>
(function () {
{$guard}  !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
  n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
  document,'script','https://connect.facebook.net/en_US/fbevents.js');
  fbq('init','{$pixelId}');
  fbq('track','PageView');
})();
</script>
{$noscript}
JS;

            $tagData = [
                'name' => 'Spectra — Meta Pixel Base Code'.($hostname ? ' — '.$hostname : ''),
                'type' => 'html',
                'parameter' => [
                    ['key' => 'html',        'type' => 'template', 'value' => $pixelScript],
                    ['key' => 'supportDocumentWrite', 'type' => 'boolean', 'value' => 'false'],
                ],
                'firingTriggerId' => ['2147479553'], // GTM built-in "All Pages" trigger ID
            ];

            $attempt = $this->createTagInWritableWorkspace($customer, $tagData, $accessToken);
            $response = $attempt['response'];

            if (! $response['success']) {
                // An existing tag of the same name means this customer is
                // already covered; there is nothing to add.
                if (str_contains($response['error'] ?? '', 'duplicate name')) {
                    return ['success' => true, 'existing' => true];
                }

                return ['success' => false, 'error' => 'Failed to create Meta Pixel tag: '.($response['error'] ?? 'Unknown error')];
            }

            return ['success' => true, 'tag_id' => $response['data']['tagId'] ?? null];
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * A workspace that can still be written to.
     *
     * Publishing freezes a workspace: GTM marks it submitted and rejects every
     * subsequent write with "Workspace is already submitted". Because the
     * customer record stores one workspace id forever, the first publish left
     * the platform permanently unable to add another tag — the container could
     * be set up once and never touched again.
     *
     * Creating a new workspace is the documented way forward, so rotate to one
     * and remember it.
     */
    /**
     * Is this failure the workspace's fault rather than the tag's?
     *
     * Two ways a stored workspace stops working, and only one was handled.
     * Publishing freezes it — "Workspace is already submitted". But publishing
     * can also consume it outright: GTM removes the workspace and leaves a fresh
     * Default Workspace behind, so the id on the customer record now points at
     * nothing and every write 404s.
     *
     * That second case stranded the sitetospend record: the workspace was gone,
     * rotation never fired because the error did not say "already submitted",
     * and the fix was to repoint the record by hand.
     */
    private function workspaceIsUnusable(array $response): bool
    {
        $error = $response['error'] ?? '';
        $status = $response['status_code'] ?? null;

        return str_contains($error, 'already submitted')
            || $status === 404
            || str_contains($error, 'not found')
            || str_contains($error, 'Not Found');
    }

    private function rotateWorkspace(Customer $customer, string $accessToken, ?callable $authorize = null): ?string
    {
        $containerPath = $customer->gtm_config['container_path']
            ?? "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}";

        // Reuse before creating. GTM caps workspaces per container and refuses
        // further creates with "Resource exhausted", and the platform token has
        // no delete scope to clean up after itself — so a strategy of always
        // minting a new workspace runs the container into a wall it cannot dig
        // out of. Publishing consumes the submitted workspace and GTM leaves a
        // fresh Default Workspace behind, which is exactly what to pick up.
        $existing = $this->makeApiCall('GET', "/{$containerPath}/workspaces", $accessToken);

        $candidates = $existing['success'] ? ($existing['data']['workspace'] ?? []) : [];

        // Prefer the Default Workspace GTM leaves behind after a publish. The
        // previous comparator never returned 0 and did not actually order the
        // list — it only happened to work.
        $isDefault = fn ($w) => ($w['name'] ?? '') === 'Default Workspace' ? 1 : 0;
        usort($candidates, fn ($a, $b) => $isDefault($b) <=> $isDefault($a));

        foreach ($candidates as $workspace) {
            $id = $workspace['workspaceId'] ?? null;

            if (! $id || (string) $id === (string) $customer->gtm_workspace_id) {
                continue;
            }

            if ($authorize) {
                $authorize();
            }
            $this->rememberWorkspace($customer, $id);

            return "{$containerPath}/workspaces/{$id}";
        }

        if ($authorize) {
            $authorize();
        }
        $response = $this->makeApiCall('POST', "/{$containerPath}/workspaces", $accessToken, [
            // No colons: GTM rejects them in workspace names, which is not
            // obvious from a timestamp until the create fails.
            'name' => 'Spectra '.now()->format('Y-m-d Hi'),
            'description' => 'Created automatically because the previous workspace had been published.',
        ]);

        if (! $response['success']) {
            Log::error('GTMContainerService: could not create a replacement workspace', [
                'customer_id' => $customer->id,
                'error' => $response['error'] ?? 'unknown',
            ]);

            return null;
        }

        $workspaceId = $response['data']['workspaceId'] ?? null;

        if (! $workspaceId) {
            return null;
        }

        if ($authorize) {
            $authorize();
        }
        $this->rememberWorkspace($customer, $workspaceId);

        return "{$containerPath}/workspaces/{$workspaceId}";
    }

    /**
     * Store the workspace the platform should write to from now on.
     */
    private function rememberWorkspace(Customer $customer, string $workspaceId): void
    {
        $config = $customer->gtm_config ?? [];
        $config['workspace_id'] = $workspaceId;

        $customer->update(['gtm_workspace_id' => $workspaceId, 'gtm_config' => $config]);

        Log::info('GTMContainerService: switched to a writable workspace', [
            'customer_id' => $customer->id,
            'workspace_id' => $workspaceId,
        ]);
    }

    /**
     * Create a tag, rotating to a fresh workspace if the current one is frozen.
     *
     * @param  array<string, mixed>  $tagData
     * @return array{response: array<string, mixed>, workspace_path: string}
     */
    private function createTagInWritableWorkspace(Customer $customer, array $tagData, string $accessToken): array
    {
        $workspacePath = $this->getWorkspacePath($customer);
        $response = $this->makeApiCall('POST', "/{$workspacePath}/tags", $accessToken, $tagData);

        if (! $response['success'] && $this->workspaceIsUnusable($response)) {
            $rotated = $this->rotateWorkspace($customer, $accessToken);

            if ($rotated) {
                $workspacePath = $rotated;
                $response = $this->makeApiCall('POST', "/{$workspacePath}/tags", $accessToken, $tagData);
            }
        }

        return ['response' => $response, 'workspace_path' => $workspacePath];
    }

    /**
     * The bare hostname a customer's site runs on, or null if unknown.
     *
     * Containers are normally one per customer, where this changes nothing. It
     * matters when a container is shared — the vertical skins run the same
     * application on their own domains off one container, and without a host
     * check every brand's pixel fires on every other brand's traffic.
     */
    private function customerHostname(Customer $customer): ?string
    {
        if (! $customer->website) {
            return null;
        }

        $host = parse_url($customer->website, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\\./', '', strtolower($host));
    }

    /**
     * A guard that stops a tag firing on a domain it does not belong to.
     *
     * Matches the host itself and any subdomain of it, so www and staging
     * subdomains keep working, and returns an empty string when the customer
     * has no website recorded — a tag that cannot identify its own domain
     * should still fire rather than silently do nothing.
     */
    private function hostnameGuard(?string $hostname): string
    {
        if (! $hostname) {
            return '';
        }

        return <<<JS
  var h = location.hostname.replace(/^www\\./, '').toLowerCase();
  if (h !== '{$hostname}' && !h.endsWith('.{$hostname}')) { return; }

JS;
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
            $hostname = $this->customerHostname($customer);

            // The host is part of the name because the duplicate-name guard
            // below would otherwise make a second brand on a shared container
            // silently adopt the first brand's tag — and therefore report its
            // conversions into the first brand's pixel.
            $tagName = 'Spectra — Meta Pixel '.$eventName.($hostname ? ' — '.$hostname : '');

            $explicitTrigger = $config['firing_trigger_id'] ?? null;

            $triggerIds = $explicitTrigger
                ? [$explicitTrigger]
                : array_values(array_filter([
                    $this->getOrCreateFormSubmitTrigger($workspacePath, $accessToken),
                    $this->getOrCreateThankYouPageTrigger($workspacePath, $accessToken),
                ]));

            $script = $this->getFacebookConversionScript($eventName, $config, $hostname);

            $tagData = [
                'name' => $tagName,
                'type' => 'html',
                'parameter' => [
                    ['key' => 'html', 'type' => 'template', 'value' => $script],
                    ['key' => 'supportDocumentWrite', 'type' => 'boolean', 'value' => 'false'],
                ],
                'firingTriggerId' => $triggerIds,
            ];

            $attempt = $this->createTagInWritableWorkspace($customer, $tagData, $accessToken);
            $response = $attempt['response'];
            $workspacePath = $attempt['workspace_path'];

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
    private function getFacebookConversionScript(string $eventName, array $config = [], ?string $hostname = null): string
    {
        $payload = [];

        if (isset($config['value'])) {
            $payload[] = 'value: '.(float) $config['value'];
            $payload[] = "currency: '".($config['currency'] ?? 'AUD')."'";
        }

        $customData = $payload ? '{'.implode(', ', $payload).'}' : '{}';

        $guard = $this->hostnameGuard($hostname);

        return <<<JAVASCRIPT
<script>
(function () {
{$guard}  // The base pixel fires on All Pages, so fbq exists by the time a visitor can
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
        } catch (\Throwable $e) {
            report($e);

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
        } catch (\Throwable $e) {
            report($e);

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

            // Publishing consumes the workspace: GTM removes it and leaves a
            // fresh Default Workspace behind, so the id on the customer record
            // now points at nothing and every later write 404s. Recovering from
            // that afterwards is possible; not stranding the record in the first
            // place is better.
            $this->refreshWorkspaceAfterPublish($customer, $accessToken);

            return ['success' => true, 'version_id' => $versionId, 'published_at' => now()];
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Point the customer at a workspace that still exists.
     *
     * Best effort: a publish that succeeded must not be reported as a failure
     * because the follow-up bookkeeping did not.
     */
    private function refreshWorkspaceAfterPublish(Customer $customer, string $accessToken): void
    {
        try {
            $containerPath = $customer->gtm_config['container_path']
                ?? "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}";

            $response = $this->makeApiCall('GET', "/{$containerPath}/workspaces", $accessToken);

            if (! $response['success']) {
                return;
            }

            $workspaces = $response['data']['workspace'] ?? [];

            $chosen = collect($workspaces)->firstWhere('name', 'Default Workspace')
                ?? ($workspaces[0] ?? null);

            if ($chosen && (string) $chosen['workspaceId'] !== (string) $customer->gtm_workspace_id) {
                $this->rememberWorkspace($customer, (string) $chosen['workspaceId']);
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('GTMContainerService: could not refresh workspace after publish', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
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
                $htmlContent = app(\App\Services\Crawling\WebsiteRenderer::class)->html($customer->website);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('GTMContainerService: Browsershot failed, falling back to HTTP', ['error' => $e->getMessage()]);
                $htmlContent = app(\App\Services\Crawling\PublicWebsiteFetcher::class)->get($customer->website)->throw()->body();
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
        } catch (\Throwable $e) {
            report($e);

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
        } catch (\Throwable $e) {
            report($e);

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
        } catch (\Throwable $e) {
            report($e);

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

            } catch (\Throwable $e) {
                report($e);
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
