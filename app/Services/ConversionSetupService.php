<?php

namespace App\Services;

use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\CreateConversionAction;
use App\Services\GoogleAds\CommonServices\GetConversionActionDetails;
use App\Services\GTM\GTMContainerService;
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
     * 1. Auto-detect GTM container from live site (overrides stored ID if different)
     * 2. Create Google Ads conversion action
     * 3. Retrieve conversion ID + label from tag snippets
     * 4. Add conversion tag + trigger to the detected GTM container
     * 5. Publish the GTM container
     * 6. Persist conversion details on the customer record
     *
     * Returns an array with 'success', 'snippet' (manual fallback HTML), and 'errors'.
     */
    public function setup(Customer $customer): array
    {
        $errors = [];
        $snippet = null;

        if (!$customer->google_ads_customer_id) {
            return ['success' => false, 'errors' => ['Google Ads account not connected']];
        }

        $customerId = $customer->cleanGoogleCustomerId();

        // Step 0: Ensure we have the correct GTM container to wire the conversion tag into.
        //
        // a) Crawl the live site and extract the GTM ID from the page source.
        //    If the detected ID differs from what's stored, correct the record.
        //
        // b) If no GTM container is found on the site at all, provision a fresh one
        //    via the GTM API and return the installation snippet so the customer can
        //    add it to their site. The conversion tag is added to the new container
        //    before it's published.
        if ($customer->website) {
            $detectedGtmId = $this->detectGtmContainer($customer->website);

            if ($detectedGtmId) {
                if ($detectedGtmId !== $customer->gtm_container_id) {
                    Log::info('ConversionSetupService: Detected different GTM container on live site — correcting stored ID', [
                        'customer_id' => $customer->id,
                        'stored'      => $customer->gtm_container_id,
                        'detected'    => $detectedGtmId,
                    ]);
                    $customer->update(['gtm_container_id' => $detectedGtmId]);
                    $customer->refresh();
                }
            } elseif (!$customer->gtm_container_id) {
                // No GTM found on the live site and none stored — provision one now.
                Log::info('ConversionSetupService: No GTM container found on site — provisioning new container', [
                    'customer_id' => $customer->id,
                    'website'     => $customer->website,
                ]);
                $provision = $this->gtm->provisionContainerForCustomer($customer);
                if ($provision['success'] ?? false) {
                    $customer->refresh();
                    Log::info('ConversionSetupService: GTM container provisioned', [
                        'customer_id'  => $customer->id,
                        'container_id' => $customer->gtm_container_id,
                    ]);
                } else {
                    $errors[] = 'GTM container could not be provisioned: ' . ($provision['error'] ?? 'unknown error') . '. Install Google Tag Manager manually and re-run setup.';
                    Log::warning('ConversionSetupService: GTM provisioning failed', [
                        'customer_id' => $customer->id,
                        'error'       => $provision['error'] ?? 'unknown',
                    ]);
                }
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

        // Step 3 & 4: Wire into GTM if the container has been provisioned
        if ($customer->gtm_container_id && $conversionId && $conversionLabel) {
            try {
                $tagResult = $this->gtm->addConversionTag($customer, $conversionActionName, $conversionId, [
                    'conversion_label' => $conversionLabel,
                ]);

                if ($tagResult['success'] ?? false) {
                    $publishResult = $this->gtm->publishContainer($customer, 'Spectra: added conversion tracking tag');
                    if (!($publishResult['success'] ?? false)) {
                        $errors[] = 'GTM container publish failed: ' . ($publishResult['error'] ?? 'unknown');
                    }
                } else {
                    $errors[] = 'GTM tag creation failed: ' . ($tagResult['error'] ?? 'unknown');
                }
            } catch (\Exception $e) {
                $errors[] = 'GTM setup error: ' . $e->getMessage();
                Log::error('ConversionSetupService: GTM error', ['error' => $e->getMessage(), 'customer_id' => $customer->id]);
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
            'success'        => true,
            'resource_name'  => $resourceName,
            'conversion_id'  => $conversionId,
            'snippet'        => $snippet,
            'errors'         => $errors,
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
