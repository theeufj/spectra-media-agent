<?php

namespace App\Services\GTM;

use Illuminate\Support\Facades\Log;

class ConversionTagGenerator
{
    /**
     * Generate a Google Ads conversion tracking tag configuration.
     *
     * @param array $config Configuration array with:
     *   - 'conversion_id' (required): Google Ads conversion ID
     *   - 'conversion_label' (required): Google Ads conversion label
     *   - 'event_value' (optional): Conversion value in cents
     *   - 'currency' (optional): Currency code (e.g., USD)
     * @return array GTM tag configuration
     */
    public function generateConversionTag(array $config): array
    {
        try {
            Log::info('Generating Google Ads conversion tag', [
                'conversion_id' => $config['conversion_id'] ?? null,
            ]);

            // Validate required fields
            if (empty($config['conversion_id']) || empty($config['conversion_label'])) {
                throw new \InvalidArgumentException('conversion_id and conversion_label are required');
            }

            $tagName = $config['tag_name'] ?? 'Google Ads Conversion - ' . $config['conversion_label'];
            $eventValue = $config['event_value'] ?? null;
            $currency = $config['currency'] ?? 'USD';

            // Build Google Ads conversion tag configuration
            $tagConfig = [
                'type' => 'gaawe',  // Google Ads conversion tag type in GTM
                'name' => $tagName,
                'firingTriggerId' => $config['trigger_id'] ?? null,
                'parameter' => [
                    [
                        'type' => 'template',
                        'key' => 'conversionId',
                        'value' => $config['conversion_id'],
                    ],
                    [
                        'type' => 'template',
                        'key' => 'conversionLabel',
                        'value' => $config['conversion_label'],
                    ],
                ],
            ];

            // Add optional parameters
            if ($eventValue) {
                $tagConfig['parameter'][] = [
                    'type' => 'template',
                    'key' => 'value',
                    'value' => $eventValue,
                ];

                $tagConfig['parameter'][] = [
                    'type' => 'template',
                    'key' => 'currencyCode',
                    'value' => $currency,
                ];
            }

            Log::info('Google Ads conversion tag generated', [
                'tag_name' => $tagName,
                'conversion_id' => $config['conversion_id'],
            ]);

            return $tagConfig;
        } catch (\Exception $e) {
            Log::error('Error generating Google Ads conversion tag', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate a Facebook Pixel conversion tracking tag configuration.
     *
     * @param string $pixelId Facebook Pixel ID
     * @param array $config Additional configuration:
     *   - 'event_name' (optional): Facebook conversion event name
     *   - 'event_value' (optional): Conversion value
     *   - 'currency' (optional): Currency code
     * @return array GTM tag configuration
     */
    public function generateFacebookPixelTag(string $pixelId, array $config = []): array
    {
        try {
            Log::info('Generating Facebook Pixel tag', [
                'pixel_id' => $pixelId,
            ]);

            if (empty($pixelId)) {
                throw new \InvalidArgumentException('pixelId is required');
            }

            $eventName = $config['event_name'] ?? 'Purchase';
            $eventValue = $config['event_value'] ?? null;
            $currency = $config['currency'] ?? 'USD';

            // Build Facebook Pixel tag configuration
            $tagConfig = [
                'type' => 'html',  // Custom HTML tag in GTM
                'name' => $config['tag_name'] ?? 'Facebook Pixel - ' . $eventName,
                'html' => $this->getFacebookPixelCode($pixelId, $eventName, $eventValue, $currency),
                'firingTriggerId' => $config['trigger_id'] ?? null,
            ];

            Log::info('Facebook Pixel tag generated', [
                'pixel_id' => $pixelId,
                'event_name' => $eventName,
            ]);

            return $tagConfig;
        } catch (\Exception $e) {
            Log::error('Error generating Facebook Pixel tag', [
                'pixel_id' => $pixelId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate a Google Analytics 4 event tracking tag configuration.
     *
     * @param string $measurementId Google Analytics 4 Measurement ID (G-XXXXXX)
     * @param array $config Additional configuration:
     *   - 'event_name' (required): Event name
     *   - 'event_parameters' (optional): Additional event parameters
     * @return array GTM tag configuration
     */
    public function generateGA4EventTag(string $measurementId, array $config = []): array
    {
        try {
            Log::info('Generating Google Analytics 4 event tag', [
                'measurement_id' => $measurementId,
            ]);

            if (empty($measurementId)) {
                throw new \InvalidArgumentException('measurementId is required');
            }

            if (empty($config['event_name'])) {
                throw new \InvalidArgumentException('event_name is required');
            }

            $eventName = $config['event_name'];
            $eventParameters = $config['event_parameters'] ?? [];

            // Build GA4 tag configuration
            $tagConfig = [
                'type' => 'ga4_event',  // GA4 event tag type in GTM
                'name' => $config['tag_name'] ?? 'GA4 Event - ' . $eventName,
                'parameter' => [
                    [
                        'type' => 'template',
                        'key' => 'measurementId',
                        'value' => $measurementId,
                    ],
                    [
                        'type' => 'template',
                        'key' => 'eventName',
                        'value' => $eventName,
                    ],
                ],
                'firingTriggerId' => $config['trigger_id'] ?? null,
            ];

            // Add event parameters
            foreach ($eventParameters as $key => $value) {
                $tagConfig['parameter'][] = [
                    'type' => 'template',
                    'key' => 'eventParameter.' . $key,
                    'value' => $value,
                ];
            }

            Log::info('GA4 event tag generated', [
                'measurement_id' => $measurementId,
                'event_name' => $eventName,
            ]);

            return $tagConfig;
        } catch (\Exception $e) {
            Log::error('Error generating GA4 event tag', [
                'measurement_id' => $measurementId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate HTML code for Facebook Pixel tracking.
     *
     * @param string $pixelId
     * @param string $eventName
     * @param float|null $eventValue
     * @param string $currency
     * @return string HTML/JavaScript code
     */
    private function getFacebookPixelCode(string $pixelId, string $eventName, ?float $eventValue = null, string $currency = 'USD'): string
    {
        $fbqCall = "fbq('track', '{$eventName}'";

        if ($eventValue !== null) {
            $fbqCall .= ", {value: {$eventValue}, currency: '{$currency}'}";
        }

        $fbqCall .= ");";

        return <<<JAVASCRIPT
<!-- Facebook Pixel -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '{$pixelId}');
{$fbqCall}
</script>
JAVASCRIPT;
    }

    /**
     * Generate auto-setup configuration for all major conversion platforms.
     *
     * @param array $platforms Platform credentials:
     *   - 'google_ads' => ['conversion_id', 'conversion_label']
     *   - 'facebook' => ['pixel_id']
     *   - 'ga4' => ['measurement_id']
     * @return array Configuration for all tags
     */
    public function generateAutoSetupConfiguration(array $platforms): array
    {
        try {
            Log::info('Generating auto-setup configuration for platforms', [
                'platforms' => array_keys($platforms),
            ]);

            $tagConfigs = [];

            // Generate Google Ads tags
            if (!empty($platforms['google_ads'])) {
                $tagConfigs['google_ads'] = $this->generateConversionTag($platforms['google_ads']);
            }

            // Generate Facebook Pixel tags
            if (!empty($platforms['facebook'])) {
                $tagConfigs['facebook'] = $this->generateFacebookPixelTag(
                    $platforms['facebook']['pixel_id'],
                    $platforms['facebook']
                );
            }

            // Generate GA4 tags
            if (!empty($platforms['ga4'])) {
                $tagConfigs['ga4'] = $this->generateGA4EventTag(
                    $platforms['ga4']['measurement_id'],
                    $platforms['ga4']
                );
            }

            Log::info('Auto-setup configuration generated', [
                'tag_count' => count($tagConfigs),
            ]);

            return $tagConfigs;
        } catch (\Exception $e) {
            Log::error('Error generating auto-setup configuration', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
