<?php

namespace App\Services;

use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\CreateConversionAction;
use App\Services\GoogleAds\CommonServices\GetConversionActionDetails;
use App\Services\GTM\GTMContainerService;
use Google\Ads\GoogleAds\V22\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Illuminate\Support\Facades\Log;

class ConversionSetupService
{
    public function __construct(
        private GTMContainerService $gtm
    ) {}

    /**
     * Run the full conversion tracking setup pipeline for a customer:
     * 1. Create Google Ads conversion action
     * 2. Retrieve conversion ID + label from tag snippets
     * 3. Add conversion tag + trigger to GTM container (if provisioned)
     * 4. Publish the GTM container
     * 5. Persist conversion details on the customer record
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

        $customerId = str_replace('-', '', $customer->google_ads_customer_id);

        // Step 1: Create conversion action
        $createService = new CreateConversionAction($customer);
        $conversionActionName = 'Spectra — ' . $customer->name . ' Conversion';
        $resourceName = ($createService)($customerId, $conversionActionName, ConversionActionCategory::LEAD);

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

        // Step 5: Persist on customer record
        $customer->update([
            'conversion_action_id'    => $resourceName,
            'conversion_action_label' => $conversionLabel,
            'conversion_tracking_verified_at' => now(),
        ]);

        Log::info('ConversionSetupService: Setup complete', [
            'customer_id'       => $customer->id,
            'resource_name'     => $resourceName,
            'conversion_id'     => $conversionId,
            'gtm_wired'         => $customer->gtm_container_id && $conversionId,
            'errors'            => $errors,
        ]);

        return [
            'success' => true,
            'resource_name'  => $resourceName,
            'conversion_id'  => $conversionId,
            'snippet'        => $snippet,
            'errors'         => $errors,
        ];
    }
}
