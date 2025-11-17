<?php

namespace App\Services\GTM;

use Illuminate\Support\Facades\Log;

class TriggerGenerator
{
    /**
     * Generate a page view trigger configuration.
     *
     * Fires when a user views a specific page or all pages.
     *
     * @param array $config Configuration:
     *   - 'name' (optional): Trigger name
     *   - 'url_match_type' (optional): 'all', 'contains', 'equals', 'starts_with', 'ends_with' (default: 'all')
     *   - 'page_path' (optional): Page path to match (required if not 'all')
     * @return array GTM trigger configuration
     */
    public function generatePageViewTrigger(array $config = []): array
    {
        try {
            Log::info('Generating page view trigger');

            $name = $config['name'] ?? 'Page View Trigger';
            $urlMatchType = $config['url_match_type'] ?? 'all';
            $pagePath = $config['page_path'] ?? null;

            // Build page view trigger configuration
            $triggerConfig = [
                'type' => 'pageview',
                'name' => $name,
                'parameter' => [],
            ];

            // Add URL matching parameters if not matching all pages
            if ($urlMatchType !== 'all' && $pagePath) {
                $triggerConfig['parameter'][] = [
                    'type' => 'template',
                    'key' => 'url',
                    'value' => $pagePath,
                ];

                // Map URL match type to GTM pattern type
                $patternMap = [
                    'contains' => 'contains',
                    'equals' => 'equals',
                    'starts_with' => 'starts_with',
                    'ends_with' => 'ends_with',
                ];

                $triggerConfig['parameter'][] = [
                    'type' => 'template',
                    'key' => 'urlMatchType',
                    'value' => $patternMap[$urlMatchType] ?? 'contains',
                ];
            }

            Log::info('Page view trigger generated', [
                'trigger_name' => $name,
                'url_match_type' => $urlMatchType,
            ]);

            return $triggerConfig;
        } catch (\Exception $e) {
            Log::error('Error generating page view trigger', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate a purchase/transaction trigger configuration.
     *
     * Fires when a purchase is completed (typically on order confirmation page).
     *
     * @param array $config Configuration:
     *   - 'name' (optional): Trigger name
     *   - 'confirmation_url' (optional): URL pattern for confirmation page
     *   - 'datalayer_event' (optional): DataLayer event name (default: 'purchase')
     * @return array GTM trigger configuration
     */
    public function generatePurchaseTrigger(array $config = []): array
    {
        try {
            Log::info('Generating purchase trigger');

            $name = $config['name'] ?? 'Purchase Trigger';
            $confirmationUrl = $config['confirmation_url'] ?? null;
            $dataLayerEvent = $config['datalayer_event'] ?? 'purchase';

            // Build purchase trigger configuration
            // This typically combines page view + dataLayer event matching
            $triggerConfig = [
                'type' => 'customEvent',
                'name' => $name,
                'parameter' => [
                    [
                        'type' => 'template',
                        'key' => 'eventName',
                        'value' => $dataLayerEvent,
                    ],
                ],
            ];

            // Add URL matching if confirmation URL specified
            if ($confirmationUrl) {
                $triggerConfig['parameter'][] = [
                    'type' => 'template',
                    'key' => 'url',
                    'value' => $confirmationUrl,
                ];

                $triggerConfig['parameter'][] = [
                    'type' => 'template',
                    'key' => 'urlMatchType',
                    'value' => 'contains',
                ];
            }

            Log::info('Purchase trigger generated', [
                'trigger_name' => $name,
                'datalayer_event' => $dataLayerEvent,
            ]);

            return $triggerConfig;
        } catch (\Exception $e) {
            Log::error('Error generating purchase trigger', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate a form submission trigger configuration.
     *
     * Fires when a user submits a form.
     *
     * @param array $config Configuration:
     *   - 'name' (optional): Trigger name
     *   - 'form_selector' (optional): CSS selector for form (e.g., '#contact-form')
     *   - 'form_id' (optional): Form ID to match
     *   - 'form_class' (optional): Form class to match
     * @return array GTM trigger configuration
     */
    public function generateFormSubmitTrigger(array $config = []): array
    {
        try {
            Log::info('Generating form submit trigger', [
                'form_selector' => $config['form_selector'] ?? null,
            ]);

            $name = $config['name'] ?? 'Form Submit Trigger';
            $formSelector = $config['form_selector'] ?? null;
            $formId = $config['form_id'] ?? null;
            $formClass = $config['form_class'] ?? null;

            // Build form submit trigger configuration
            $triggerConfig = [
                'type' => 'formSubmission',
                'name' => $name,
                'parameter' => [],
            ];

            // Add form matching parameters
            if ($formSelector) {
                $triggerConfig['parameter'][] = [
                    'type' => 'template',
                    'key' => 'selector',
                    'value' => $formSelector,
                ];
            }

            if ($formId) {
                $triggerConfig['parameter'][] = [
                    'type' => 'template',
                    'key' => 'formId',
                    'value' => $formId,
                ];
            }

            if ($formClass) {
                $triggerConfig['parameter'][] = [
                    'type' => 'template',
                    'key' => 'formClass',
                    'value' => $formClass,
                ];
            }

            Log::info('Form submit trigger generated', [
                'trigger_name' => $name,
            ]);

            return $triggerConfig;
        } catch (\Exception $e) {
            Log::error('Error generating form submit trigger', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate a custom event trigger configuration.
     *
     * Fires when a custom event is pushed to the DataLayer.
     *
     * @param array $config Configuration:
     *   - 'name' (optional): Trigger name
     *   - 'event_name' (required): Event name to match
     *   - 'event_condition' (optional): Additional condition
     * @return array GTM trigger configuration
     */
    public function generateCustomEventTrigger(array $config = []): array
    {
        try {
            if (empty($config['event_name'])) {
                throw new \InvalidArgumentException('event_name is required');
            }

            Log::info('Generating custom event trigger', [
                'event_name' => $config['event_name'],
            ]);

            $name = $config['name'] ?? 'Custom Event: ' . $config['event_name'];
            $eventName = $config['event_name'];

            // Build custom event trigger configuration
            $triggerConfig = [
                'type' => 'customEvent',
                'name' => $name,
                'parameter' => [
                    [
                        'type' => 'template',
                        'key' => 'eventName',
                        'value' => $eventName,
                    ],
                ],
            ];

            Log::info('Custom event trigger generated', [
                'trigger_name' => $name,
                'event_name' => $eventName,
            ]);

            return $triggerConfig;
        } catch (\Exception $e) {
            Log::error('Error generating custom event trigger', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate a scroll depth trigger configuration.
     *
     * Fires when user scrolls to a certain depth on the page.
     *
     * @param array $config Configuration:
     *   - 'name' (optional): Trigger name
     *   - 'scroll_percentage' (optional): Scroll percentage (25, 50, 75, 90, 100) (default: 50)
     * @return array GTM trigger configuration
     */
    public function generateScrollDepthTrigger(array $config = []): array
    {
        try {
            Log::info('Generating scroll depth trigger');

            $name = $config['name'] ?? 'Scroll Depth Trigger';
            $scrollPercentage = $config['scroll_percentage'] ?? 50;

            // Validate scroll percentage
            $validPercentages = [25, 50, 75, 90, 100];
            if (!in_array($scrollPercentage, $validPercentages)) {
                throw new \InvalidArgumentException('scroll_percentage must be one of: ' . implode(', ', $validPercentages));
            }

            // Build scroll depth trigger configuration
            $triggerConfig = [
                'type' => 'scrollDepth',
                'name' => $name,
                'parameter' => [
                    [
                        'type' => 'template',
                        'key' => 'verticalScrollPercentage',
                        'value' => $scrollPercentage,
                    ],
                ],
            ];

            Log::info('Scroll depth trigger generated', [
                'trigger_name' => $name,
                'scroll_percentage' => $scrollPercentage,
            ]);

            return $triggerConfig;
        } catch (\Exception $e) {
            Log::error('Error generating scroll depth trigger', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate click trigger configuration.
     *
     * Fires when a user clicks on elements matching the selector.
     *
     * @param array $config Configuration:
     *   - 'name' (optional): Trigger name
     *   - 'css_selector' (optional): CSS selector for elements to track
     *   - 'element_id' (optional): Element ID to match
     *   - 'element_class' (optional): Element class to match
     * @return array GTM trigger configuration
     */
    public function generateClickTrigger(array $config = []): array
    {
        try {
            Log::info('Generating click trigger', [
                'css_selector' => $config['css_selector'] ?? null,
            ]);

            $name = $config['name'] ?? 'Click Trigger';
            $cssSelector = $config['css_selector'] ?? null;
            $elementId = $config['element_id'] ?? null;
            $elementClass = $config['element_class'] ?? null;

            // Build click trigger configuration
            $triggerConfig = [
                'type' => 'click',
                'name' => $name,
                'parameter' => [],
            ];

            // Add element matching parameters
            if ($cssSelector) {
                $triggerConfig['parameter'][] = [
                    'type' => 'template',
                    'key' => 'selector',
                    'value' => $cssSelector,
                ];
            }

            if ($elementId) {
                $triggerConfig['parameter'][] = [
                    'type' => 'template',
                    'key' => 'elementId',
                    'value' => $elementId,
                ];
            }

            if ($elementClass) {
                $triggerConfig['parameter'][] = [
                    'type' => 'template',
                    'key' => 'elementClass',
                    'value' => $elementClass,
                ];
            }

            Log::info('Click trigger generated', [
                'trigger_name' => $name,
            ]);

            return $triggerConfig;
        } catch (\Exception $e) {
            Log::error('Error generating click trigger', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate an auto-setup trigger configuration for common events.
     *
     * @param array $events Event types to generate triggers for:
     *   - 'pageview' => config array
     *   - 'purchase' => config array
     *   - 'form_submit' => config array
     *   - 'scroll_depth' => config array
     * @return array Configuration for all triggers
     */
    public function generateAutoSetupTriggers(array $events): array
    {
        try {
            Log::info('Generating auto-setup triggers', [
                'event_types' => array_keys($events),
            ]);

            $triggerConfigs = [];

            // Generate page view triggers
            if (!empty($events['pageview'])) {
                $triggerConfigs['pageview'] = $this->generatePageViewTrigger($events['pageview']);
            }

            // Generate purchase triggers
            if (!empty($events['purchase'])) {
                $triggerConfigs['purchase'] = $this->generatePurchaseTrigger($events['purchase']);
            }

            // Generate form submit triggers
            if (!empty($events['form_submit'])) {
                $triggerConfigs['form_submit'] = $this->generateFormSubmitTrigger($events['form_submit']);
            }

            // Generate scroll depth triggers
            if (!empty($events['scroll_depth'])) {
                $triggerConfigs['scroll_depth'] = $this->generateScrollDepthTrigger($events['scroll_depth']);
            }

            Log::info('Auto-setup triggers generated', [
                'trigger_count' => count($triggerConfigs),
            ]);

            return $triggerConfigs;
        } catch (\Exception $e) {
            Log::error('Error generating auto-setup triggers', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
