<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\ConversionAction;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;

class GetConversionActionDetails extends BaseGoogleAdsService
{
    /**
     * Get details of a Conversion Action.
     *
     * @param string $customerId
     * @param string $resourceName
     * @return array|null Array containing 'id', 'name', 'tag_snippets' (which contains the label)
     */
    public function __invoke(string $customerId, string $resourceName): ?array
    {
        $this->ensureClient();

        $query = "SELECT conversion_action.id, conversion_action.name, conversion_action.tag_snippets " .
                 "FROM conversion_action " .
                 "WHERE conversion_action.resource_name = '$resourceName'";

        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $stream = $googleAdsServiceClient->search($customerId, $query);

            foreach ($stream->iterateAllElements() as $googleAdsRow) {
                /** @var ConversionAction $conversionAction */
                $conversionAction = $googleAdsRow->getConversionAction();
                
                $snippets = [];
                foreach ($conversionAction->getTagSnippets() as $snippet) {
                    $snippets[] = [
                        'type' => $snippet->getType(), // Enum
                        'page_format' => $snippet->getPageFormat(), // Enum
                        'global_site_tag' => $snippet->getGlobalSiteTag(),
                        'event_snippet' => $snippet->getEventSnippet(),
                    ];
                }

                // Extract label from event snippet if possible, or use the ID.
                // Actually, the API doesn't explicitly give "conversion_label" as a field in V14+ easily?
                // Wait, tag_snippets contains the code. We might need to parse it or find another field.
                // Let's check if there is a better field.
                // In older APIs it was tracker.
                // Actually, for GTM we need Conversion ID and Conversion Label.
                // Conversion ID is usually the account level ID (or part of the snippet).
                // Conversion Label is specific to the action.
                
                // Let's look at the snippet.
                // The event snippet looks like: gtag('event', 'conversion', {'send_to': 'AW-123456789/AbCdEfGhIjKlMnOpQr'});
                // 'AW-123456789' is the Conversion ID (Account level usually).
                // 'AbCdEfGhIjKlMnOpQr' is the Conversion Label.
                
                // We can parse the 'send_to' from the snippet.
                
                $conversionLabel = null;
                $conversionId = null;
                
                foreach ($conversionAction->getTagSnippets() as $snippet) {
                    if ($snippet->getType() === \Google\Ads\GoogleAds\V22\Enums\TrackingCodeTypeEnum\TrackingCodeType::WEBPAGE) {
                        $eventSnippet = $snippet->getEventSnippet();
                        if (preg_match("/'send_to': '([^']+)'/", $eventSnippet, $matches)) {
                            $sendTo = $matches[1];
                            $parts = explode('/', $sendTo);
                            if (count($parts) === 2) {
                                $conversionId = $parts[0]; // e.g. AW-123456789
                                $conversionLabel = $parts[1];
                            }
                        }
                    }
                }

                return [
                    'id' => $conversionAction->getId(),
                    'name' => $conversionAction->getName(),
                    'conversion_id' => $conversionId, // The AW-XXX ID
                    'conversion_label' => $conversionLabel,
                ];
            }

            return null;
        } catch (GoogleAdsException $e) {
            $this->logError("Failed to get conversion action details: " . $e->getMessage());
            return null;
        }
    }
}
