<?php

namespace App\Services;

use App\Models\Customer;
use App\Services\FacebookAds\PixelService;
use App\Services\GTM\GTMContainerService;
use App\Services\MicrosoftAds\ConversionTrackingService as MicrosoftConversionTrackingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConversionSetupService
{
    public function __construct(
        private GTMContainerService $gtm
    ) {}

    /** Provision website measurement. A campaign's selected conversion is configured and tested separately. */
    public function setup(Customer $customer): array
    {
        if ($customer->service_type === 'setup_only') {
            // The paid setup workflow collects the actual business goal and a
            // precise trigger. Never manufacture a generic Signup/any-form goal.
            return ['success' => true, 'waiting_for_goal' => true, 'resource_name' => null,
                'conversion_id' => null, 'snippet' => null, 'errors' => []];
        }
        $errors = [];
        $snippet = null;

        if (! $customer->google_ads_customer_id && ! $customer->facebook_ads_account_id && ! $customer->microsoft_ads_account_id) {
            return ['success' => false, 'errors' => ['No advertising account is connected']];
        }

        // Step 0: Ensure a Spectra-managed GTM container exists for this customer.
        //
        // We always use a container provisioned under the Spectra platform GTM account
        // so we have API write access to add and publish tags. We cannot modify containers
        // that live in the customer's own Google account.
        //
        // Any GTM container detected on the customer's live site is recorded as metadata
        // only — we never attempt to write into it.
        if ($customer->website) {
            $detectedGtmId = $this->detectGtmContainer($customer->website);
            if ($detectedGtmId) {
                Log::info('ConversionSetupService: Customer already has GTM on site (read-only reference)', [
                    'customer_id' => $customer->id,
                    'detected' => $detectedGtmId,
                ]);
            }
        }

        $spectraAccountId = config('services.gtm.platform_account_id');
        $hasSpectraContainer = $spectraAccountId
            && $customer->gtm_account_id === $spectraAccountId
            && $customer->gtm_container_id
            && $customer->gtm_workspace_id;

        if (! $hasSpectraContainer) {
            Log::info('ConversionSetupService: No Spectra-managed GTM container — provisioning one now', [
                'customer_id' => $customer->id,
            ]);
            $provision = $this->gtm->provisionContainerForCustomer($customer);
            if ($provision['success'] ?? false) {
                $customer->refresh();
                Log::info('ConversionSetupService: GTM container provisioned', [
                    'customer_id' => $customer->id,
                    'container_id' => $customer->gtm_container_id,
                ]);
            } else {
                $errors[] = 'GTM container could not be provisioned: '.($provision['error'] ?? 'unknown error');
                Log::warning('ConversionSetupService: GTM provisioning failed', [
                    'customer_id' => $customer->id,
                    'error' => $provision['error'] ?? 'unknown',
                ]);
            }
        }

        // Install base measurement only. The campaign owner selects the business
        // outcome and exact website trigger before campaign-specific tags are published.
        if ($customer->gtm_container_id && $customer->gtm_workspace_id && empty($errors)) {
            // Meta Pixel tag
            try {
                $pixelId = $customer->facebook_ads_account_id ? (new PixelService($customer))->resolvePixelId() : null;
                if ($pixelId) {
                    $fbResult = $this->gtm->addFacebookPixelTag($customer, $pixelId);
                    if (! ($fbResult['success'] ?? false)) {
                        $errors[] = 'GTM Meta Pixel tag failed: '.($fbResult['error'] ?? 'unknown');
                    }

                }
            } catch (\Throwable $e) {
                report($e);
                $errors[] = 'GTM Meta Pixel error: '.$e->getMessage();
                Log::warning('ConversionSetupService: Meta Pixel GTM error', ['error' => $e->getMessage(), 'customer_id' => $customer->id]);
            }

            // Microsoft UET tag
            try {
                if ($customer->microsoft_ads_account_id) {
                    $uetTagId = (new MicrosoftConversionTrackingService($customer))->resolveUetTagId();
                    if ($uetTagId) {
                        $msResult = $this->gtm->addMicrosoftUetTag($customer, $uetTagId);
                        if (! ($msResult['success'] ?? false)) {
                            $errors[] = 'GTM Microsoft UET tag failed: '.($msResult['error'] ?? 'unknown');
                        }
                    }
                }
            } catch (\Throwable $e) {
                report($e);
                $errors[] = 'GTM Microsoft UET error: '.$e->getMessage();
                Log::warning('ConversionSetupService: Microsoft UET GTM error', ['error' => $e->getMessage(), 'customer_id' => $customer->id]);
            }

            // Publish once — covers all tags added above
            try {
                $publishResult = $this->gtm->publishContainer($customer, 'Site to Spend website measurement');
                if (! ($publishResult['success'] ?? false)) {
                    $errors[] = 'GTM container publish failed: '.($publishResult['error'] ?? 'unknown');
                }
            } catch (\Throwable $e) {
                report($e);
                $errors[] = 'GTM publish error: '.$e->getMessage();
            }

            // Fetch snippet HTML for manual fallback display
            try {
                $snippetResult = $this->gtm->getSnippetHtml($customer->gtm_container_id);
                $snippet = $snippetResult['head'] ?? null;
            } catch (\Throwable $e) {
                report($e);
                // Non-fatal
            }
        }

        if (! $customer->gtm_container_id || ! $customer->gtm_workspace_id) {
            $errors[] = 'The measurement workspace is incomplete.';
        }

        return [
            'success' => empty($errors), 'waiting_for_goal' => true,
            'resource_name' => null, 'conversion_id' => null, 'snippet' => $snippet,
            'facebook_pixel_id' => $customer->fresh()->facebook_pixel_id,
            'microsoft_uet_tag_id' => $customer->fresh()->microsoft_uet_tag_id, 'errors' => $errors,
        ];
    }

    /**
     * Detect the GTM container ID from a live website by scraping its HTML.
     * Handles both the gtm.js script tag and the noscript iframe fallback.
     */
    public function detectGtmContainer(string $url): ?string
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Spectra/1.0)'])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            // Match GTM-XXXXXXXX from googletagmanager.com URLs in the page source
            if (preg_match('/GTM-[A-Z0-9]+/', $html, $matches)) {
                return $matches[0];
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('ConversionSetupService: Could not detect GTM container from site', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
