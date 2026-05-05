<?php

namespace App\Services;

use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\CreateConversionAction;
use App\Services\GoogleAds\CommonServices\GetConversionActionDetails;
use App\Services\FacebookAds\PixelService;
use App\Services\GTM\GTMContainerService;
use App\Services\MicrosoftAds\ConversionTrackingService as MicrosoftConversionTrackingService;
use Google\Ads\GoogleAds\V22\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConversionSetupService
{
    public function __construct(
        private GTMContainerService $gtm
    ) {}

    /**
     * Run the full conversion tracking setup pipeline for a customer:
     * 1. Ensure a Spectra-managed GTM container exists (always — we cannot write
     *    tags into containers owned by the customer's own Google account)
     * 2. Create Google Ads conversion action
     * 3. Retrieve conversion ID + label from tag snippets
     * 4. Add conversion tag + trigger to the Spectra GTM container
     * 5. Publish the Spectra GTM container
     * 6. Persist conversion details on the customer record
     *
     * Returns an array with 'success', 'snippet' (install snippet HTML), and 'errors'.
     */
    public function setup(Customer $customer): array
    {
        $errors = [];
        $snippet = null;

        if (!$customer->google_ads_customer_id) {
            return ['success' => false, 'errors' => ['Google Ads account not connected']];
        }

        $customerId = $customer->cleanGoogleCustomerId();

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
                    'detected'    => $detectedGtmId,
                ]);
            }
        }

        $spectraAccountId    = config('services.gtm.platform_account_id');
        $hasSpectraContainer = $spectraAccountId
            && $customer->gtm_account_id === $spectraAccountId
            && $customer->gtm_container_id
            && $customer->gtm_workspace_id;

        if (!$hasSpectraContainer) {
            Log::info('ConversionSetupService: No Spectra-managed GTM container — provisioning one now', [
                'customer_id' => $customer->id,
            ]);
            $provision = $this->gtm->provisionContainerForCustomer($customer);
            if ($provision['success'] ?? false) {
                $customer->refresh();
                Log::info('ConversionSetupService: GTM container provisioned', [
                    'customer_id'  => $customer->id,
                    'container_id' => $customer->gtm_container_id,
                ]);
            } else {
                $errors[] = 'GTM container could not be provisioned: ' . ($provision['error'] ?? 'unknown error');
                Log::warning('ConversionSetupService: GTM provisioning failed', [
                    'customer_id' => $customer->id,
                    'error'       => $provision['error'] ?? 'unknown',
                ]);
            }
        }

        // Step 1: Create conversion action
        $createService = new CreateConversionAction($customer);
        $conversionActionName = 'Spectra — ' . $customer->name . ' Conversion';
        $resourceName = ($createService)($customerId, $conversionActionName, ConversionActionCategory::SIGNUP);

        if (!$resourceName) {
            return ['success' => false, 'errors' => ['Failed to create Google Ads conversion action']];
        }

        // Step 2: Get conversion ID + label for GTM tag
        $detailsService = new GetConversionActionDetails($customer);
        $details = ($detailsService)($customerId, $resourceName);

        if (!$details) {
            Log::warning('ConversionSetupService: Could not retrieve conversion action details', [
                'customer_id' => $customer->id,
                'resource_name' => $resourceName,
            ]);
        }

        $conversionLabel = $details['conversion_label'] ?? null;
        $conversionId    = $details['conversion_id'] ?? null;

        // Step 3 & 4: Wire tags into GTM if the container has been provisioned
        if ($customer->gtm_container_id && $conversionId && $conversionLabel) {
            try {
                // Google Ads conversion tag
                $tagResult = $this->gtm->addConversionTag($customer, $conversionActionName, $conversionId, [
                    'conversion_label' => $conversionLabel,
                ]);

                if (!($tagResult['success'] ?? false)) {
                    $errors[] = 'GTM Google Ads tag creation failed: ' . ($tagResult['error'] ?? 'unknown');
                }
            } catch (\Exception $e) {
                $errors[] = 'GTM Google Ads tag error: ' . $e->getMessage();
                Log::error('ConversionSetupService: GTM Google Ads tag error', ['error' => $e->getMessage(), 'customer_id' => $customer->id]);
            }

            // Meta Pixel tag
            try {
                $pixelId = (new PixelService($customer))->resolvePixelId();
                if ($pixelId) {
                    $fbResult = $this->gtm->addFacebookPixelTag($customer, $pixelId);
                    if (!($fbResult['success'] ?? false)) {
                        $errors[] = 'GTM Meta Pixel tag failed: ' . ($fbResult['error'] ?? 'unknown');
                    }
                }
            } catch (\Exception $e) {
                $errors[] = 'GTM Meta Pixel error: ' . $e->getMessage();
                Log::warning('ConversionSetupService: Meta Pixel GTM error', ['error' => $e->getMessage(), 'customer_id' => $customer->id]);
            }

            // Microsoft UET tag
            try {
                if ($customer->microsoft_ads_account_id) {
                    $uetTagId = (new MicrosoftConversionTrackingService($customer))->resolveUetTagId();
                    if ($uetTagId) {
                        $msResult = $this->gtm->addMicrosoftUetTag($customer, $uetTagId);
                        if (!($msResult['success'] ?? false)) {
                            $errors[] = 'GTM Microsoft UET tag failed: ' . ($msResult['error'] ?? 'unknown');
                        }
                    }
                }
            } catch (\Exception $e) {
                $errors[] = 'GTM Microsoft UET error: ' . $e->getMessage();
                Log::warning('ConversionSetupService: Microsoft UET GTM error', ['error' => $e->getMessage(), 'customer_id' => $customer->id]);
            }

            // Lead event tags (fire on form submission)
            try {
                $customer->refresh();
                if ($customer->facebook_pixel_id) {
                    $this->gtm->addFacebookLeadEventTag($customer, $customer->facebook_pixel_id);
                }
                if ($customer->microsoft_uet_tag_id) {
                    $this->gtm->addMicrosoftLeadEventTag($customer, $customer->microsoft_uet_tag_id);
                }
            } catch (\Exception $e) {
                Log::warning('ConversionSetupService: Lead event tag error', ['error' => $e->getMessage(), 'customer_id' => $customer->id]);
            }

            // Publish once — covers all tags added above
            try {
                $publishResult = $this->gtm->publishContainer($customer, 'Spectra: added Google Ads + Meta Pixel + Microsoft UET tags');
                if (!($publishResult['success'] ?? false)) {
                    $errors[] = 'GTM container publish failed: ' . ($publishResult['error'] ?? 'unknown');
                }
            } catch (\Exception $e) {
                $errors[] = 'GTM publish error: ' . $e->getMessage();
            }

            // Fetch snippet HTML for manual fallback display
            try {
                $snippetResult = $this->gtm->getSnippetHtml($customer->gtm_container_id);
                $snippet = $snippetResult['head'] ?? null;
            } catch (\Exception $e) {
                // Non-fatal
            }
        }

        // Step 5: Persist on customer record.
        // Only mark as verified when GTM published successfully — setting it here would
        // hide the fact that the tag may not be live on the customer's site yet.
        $gtmPublished = empty($errors);

        $customer->update([
            'conversion_action_id'            => $resourceName,
            'conversion_action_label'         => $conversionLabel,
            'conversion_tracking_verified_at' => $gtmPublished ? now() : null,
        ]);

        Log::info('ConversionSetupService: Setup complete', [
            'customer_id'    => $customer->id,
            'resource_name'  => $resourceName,
            'conversion_id'  => $conversionId,
            'gtm_id'         => $customer->gtm_container_id,
            'gtm_wired'      => $customer->gtm_container_id && $conversionId,
            'errors'         => $errors,
        ]);

        return [
            'success'               => true,
            'resource_name'         => $resourceName,
            'conversion_id'         => $conversionId,
            'snippet'               => $snippet,
            'facebook_pixel_id'     => $customer->fresh()->facebook_pixel_id,
            'microsoft_uet_tag_id'  => $customer->fresh()->microsoft_uet_tag_id,
            'errors'                => $errors,
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

            if (!$response->successful()) {
                return null;
            }

            $html = $response->body();

            // Match GTM-XXXXXXXX from googletagmanager.com URLs in the page source
            if (preg_match('/GTM-[A-Z0-9]+/', $html, $matches)) {
                return $matches[0];
            }
        } catch (\Exception $e) {
            Log::warning('ConversionSetupService: Could not detect GTM container from site', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
