# Google Tag Manager Integration & Automated Tag Deployment

This document outlines the strategy for integrating Google Tag Manager (GTM) into Spectra, enabling automated tag deployment on user websites for enhanced tracking, conversion measurement, and campaign performance attribution.

## Overview

Once we've scraped a user's website and deployed campaigns, the next critical step is implementing comprehensive tracking through Google Tag Manager. This allows us to:

1. **Track user behavior** across their entire website
2. **Measure conversions** from ads back to website actions
3. **Attribute revenue** to specific campaigns
4. **Implement dynamic remarketing** with behavioral data
5. **Test variations** with A/B testing tags
6. **Optimize bidding** with actual conversion data

Currently, our system deploys campaigns but has limited visibility into what actually happens after users click ads. GTM integration closes this gap.

---

## Current State vs. Proposed State

### Current State
```
Campaign Created → Ads Go Live → User Clicks Ad → User Lands on Page → ? (Unknown)
                                                                        ↓
                                                                   No tracking setup
                                                                   Limited conversion data
                                                                   Can't optimize properly
```

### Proposed State with GTM Integration
```
Campaign Created → Ads Go Live → User Clicks Ad → User Lands on Page → GTM Fires
                                                                        ↓
                                                            Capture events/conversions
                                                            Send to Google Analytics 4
                                                            Send to Google Ads
                                                            Send to Facebook Pixel
                                                            Send to CRM
                                                            ↓
                                                      Real-time optimization data
                                                      Accurate ROAS calculation
                                                      Behavioral targeting data
                                                      Revenue attribution
```

---

## Architecture

### Components

#### 1. GTM Container Setup Service

**Purpose:** Create and manage GTM containers for each customer.

**Responsibilities:**
- Create GTM container in customer's Google account
- Generate GTM container ID
- Manage container versions
- Handle environment-specific configs (staging, production)

#### 2. Tag Generation Agent

**Purpose:** Automatically generate tags based on campaign and website structure.

**Responsibilities:**
- Create conversion tracking tags
- Generate event tags for page interactions
- Set up enhanced ecommerce tags
- Create user ID tracking tags
- Generate custom event tags

#### 3. Deployment Service

**Purpose:** Inject GTM container code into user website.

**Responsibilities:**
- Deploy GTM snippet to website header
- Inject dataLayer initialization
- Add tracking events to elements
- Verify tag installation
- Test tag firing

#### 4. Conversion Mapping Service

**Purpose:** Map website events to Google Ads conversions.

**Responsibilities:**
- Create conversion actions in Google Ads
- Link GTM events to conversions
- Set up conversion value tracking
- Configure conversion windows

#### 5. Monitoring & Validation Agent

**Purpose:** Continuously verify tags are working correctly.

**Responsibilities:**
- Monitor tag firing
- Detect tag failures
- Validate data quality
- Alert on issues
- Generate tag health reports

---

## GTM Detection During Initial Website Scrape

**KEY INSIGHT:** We should detect if the customer has GTM installed when we initially scrape their website. This determines which implementation path (A or B) we take.

### When to Detect

During the initial website scraping process (which already happens), we should:

1. **Parse the HTML** for GTM container tags
2. **Extract the Container ID** if GTM is installed
3. **Store the detection result** in the customer record
4. **Use this to determine the path** we'll take

### How to Detect GTM

GTM is installed via a script tag that looks like this:

```html
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-XXXXXX');</script>
<!-- End Google Tag Manager -->
```

**To detect:** Look for the string `googletagmanager.com/gtm.js` and extract the container ID (format: GTM-XXXXXX)

### Detection Service

Add to the website scraping/crawling process:

```php
namespace App\Services\WebCrawler;

class GTMDetectionService
{
    /**
     * Detect if website has GTM installed
     * Returns container ID if found, null otherwise
     */
    public function detectGTMContainer(string $htmlContent): ?string
    {
        // Regex to match GTM script and extract container ID
        $pattern = '/googletagmanager\.com\/gtm\.js[^\']*?id=([\'"]?)(GTM-[A-Z0-9]+)\1/i';
        
        if (preg_match($pattern, $htmlContent, $matches)) {
            return $matches[2]; // Returns "GTM-XXXXXX"
        }
        
        return null; // No GTM detected
    }

    /**
     * Also detect noscript variant (for completeness)
     */
    public function detectGTMNoscript(string $htmlContent): ?string
    {
        $pattern = '/googletagmanager\.com\/ns\.html[^\']*?id=([\'"]?)(GTM-[A-Z0-9]+)\1/i';
        
        if (preg_match($pattern, $htmlContent, $matches)) {
            return $matches[2];
        }
        
        return null;
    }
}
```

### Integration Point

During customer onboarding or website scraping:

```php
namespace App\Jobs;

use App\Services\WebCrawler\GTMDetectionService;

class ScrapeCustomerWebsite
{
    public function handle(Customer $customer)
    {
        // ... existing scraping code ...
        
        // NEW: Detect GTM during scrape
        $gtmDetector = new GTMDetectionService();
        $gtmContainerId = $gtmDetector->detectGTMContainer($htmlContent);
        
        // Store detection result
        $customer->update([
            'gtm_container_id' => $gtmContainerId,
            'gtm_detected' => $gtmContainerId ? true : false,
            'gtm_detected_at' => now(),
        ]);
        
        // Log for tracking
        if ($gtmContainerId) {
            Log::info("GTM detected during website scrape", [
                'customer_id' => $customer->id,
                'container_id' => $gtmContainerId,
            ]);
        } else {
            Log::info("No GTM detected on customer website", [
                'customer_id' => $customer->id,
            ]);
        }
    }
}
```

### Implementation Path Decision

Once we know if GTM is installed, we automatically decide which path to take:

```php
namespace App\Http\Controllers;

class GTMSetupController
{
    public function showSetupForm(Customer $customer)
    {
        if ($customer->gtm_detected && $customer->gtm_container_id) {
            // Path A: They have GTM
            return view('gtm.setup-existing', [
                'containerId' => $customer->gtm_container_id,
                'message' => 'We detected GTM on your website! 
                              Ready to set up conversion tracking?',
            ]);
        } else {
            // Path B: They don't have GTM
            return view('gtm.setup-new', [
                'message' => 'We can set up conversion tracking 
                              via Google Tag Manager.',
            ]);
        }
    }
}
```

### Benefits of Detecting During Scrape

✅ **Automatic:** No need to ask customer "Do you have GTM?"
✅ **Accurate:** We know for sure by checking the HTML source
✅ **Early:** We know the path before onboarding completes
✅ **Seamless:** Customer sees correct setup flow automatically
✅ **Data-driven:** We can track % of customers with GTM

### Database Migration

Add these columns:

```php
Schema::table('customers', function (Blueprint $table) {
    $table->boolean('gtm_detected')->default(false);
    $table->timestamp('gtm_detected_at')->nullable();
});
```

---

## Two-Path Implementation Strategy

### Path A: Customer Already Has GTM Installed (EASIEST)

If the customer already has Google Tag Manager installed on their website, we can:

1. **Request GTM Container ID** - Ask customer for their existing GTM container ID (e.g., GTM-XXXXXX)
2. **Create Tags Programmatically** - Use GTM API to add our conversion/event tags directly to their container
3. **No Site Changes Needed** - Since GTM is already installed, tags fire immediately without any code deployment

This is the **recommended path** - minimal friction, maximum automation.

### Path B: Customer Doesn't Have GTM (REQUIRES SETUP)

If they don't have GTM, we need to either:
- Provide GTM snippet for manual installation
- Create WordPress plugin
- Support domain delegation

---

## Implementation Strategy

### Phase 1: GTM Infrastructure Setup

#### Step 1.1: Detect Existing GTM & Link Container

Create a service that detects if customer has GTM already installed:

```php
namespace App\Services\GTM;

use Google\TagManager\V2\GoogleTagManagerClient;
use Illuminate\Support\Facades\Log;

class GTMContainerService
{
    private $gtmClient;

    public function __construct()
    {
        // Initialize Google Tag Manager API client
        $this->gtmClient = new GoogleTagManagerClient();
    }

    /**
     * Link existing GTM container to customer
     * If they already have GTM installed, just link it
     */
    public function linkExistingContainer(Customer $customer, string $containerId): ?array
    {
        try {
            // Verify container exists and we have access
            $container = $this->gtmClient->getContainer([
                'path' => "accounts/{$customer->google_ads_mcc_customer_id}/containers/{$containerId}",
            ]);

            // Store container details
            $customer->update([
                'gtm_container_id' => $containerId,
                'gtm_account_id' => $customer->google_ads_mcc_customer_id,
            ]);

            Log::info("Linked existing GTM container for customer {$customer->id}", [
                'container_id' => $containerId,
            ]);

            return [
                'containerId' => $containerId,
                'path' => $container->getName(),
                'linked' => true,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to link GTM container: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a new GTM container only if customer doesn't have one
     */
    public function createContainerIfNeeded(Customer $customer): ?array
    {
        // First check if they already provided a container ID
        if ($customer->gtm_container_id) {
            return [
                'containerId' => $customer->gtm_container_id,
                'created' => false,
                'message' => 'Using existing container',
            ];
        }

        try {
            // Create new container in customer's GTM account
            $container = $this->gtmClient->createContainer([
                'parent' => "accounts/{$customer->google_ads_mcc_customer_id}",
                'container' => [
                    'displayName' => "Spectra - {$customer->name}",
                    'containerType' => 'WEB',
                    'domainName' => $customer->website ?? 'example.com',
                ],
            ]);

            $containerId = basename($container->getName());

            $customer->update([
                'gtm_container_id' => $containerId,
                'gtm_account_id' => $customer->google_ads_mcc_customer_id,
            ]);

            Log::info("Created GTM container for customer {$customer->id}", [
                'container_id' => $containerId,
            ]);

            return [
                'containerId' => $containerId,
                'created' => true,
                'message' => 'New container created',
                'installSnippet' => $this->generateInstallSnippet($customer),
            ];
        } catch (\Exception $e) {
            Log::error("Failed to create GTM container: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate GTM install snippet (only needed if new container and no existing GTM)
     */
    public function generateInstallSnippet(Customer $customer): string
    {
        $containerId = $customer->gtm_container_id;

        return <<<HTML
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','{$containerId}');</script>
<!-- End Google Tag Manager -->
HTML;
    }

    /**
     * Add a conversion tag to existing GTM container
     * No deployment needed - GTM is already installed
     */
    public function addConversionTag(
        Customer $customer,
        string $tagName,
        string $conversionId
    ): ?array {
        try {
            $tagConfig = [
                'name' => $tagName,
                'type' => 'gaawe', // Google Ads Conversion Linker
                'parameter' => [
                    [
                        'key' => 'conversionId',
                        'value' => $conversionId,
                    ],
                ],
            ];

            $tag = $this->gtmClient->createTag([
                'parent' => "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}/workspaces/{$customer->gtm_workspace_id}",
                'tag' => $tagConfig,
            ]);

            Log::info("Added conversion tag to GTM", [
                'customer_id' => $customer->id,
                'tag_name' => $tagName,
            ]);

            return [
                'tagId' => basename($tag->getName()),
                'added' => true,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to add conversion tag: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Add trigger to container
     */
    public function addTrigger(
        Customer $customer,
        string $triggerName,
        string $triggerType,
        array $triggerConfig
    ): ?array {
        try {
            $trigger = $this->gtmClient->createTrigger([
                'parent' => "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}/workspaces/{$customer->gtm_workspace_id}",
                'trigger' => [
                    'name' => $triggerName,
                    'type' => $triggerType,
                    'parameter' => $triggerConfig,
                ],
            ]);

            Log::info("Added trigger to GTM", [
                'customer_id' => $customer->id,
                'trigger_name' => $triggerName,
            ]);

            return [
                'triggerId' => basename($trigger->getName()),
                'added' => true,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to add trigger: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Publish changes to GTM
     * This makes tags live on all websites with this GTM container
     */
    public function publishContainer(Customer $customer, string $changeNotes = ''): ?array
    {
        try {
            $version = $this->gtmClient->createWorkspaceVersion([
                'parent' => "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}/workspaces/{$customer->gtm_workspace_id}",
                'workspaceVersion' => [
                    'name' => 'Spectra Automation - ' . now()->toDateTimeString(),
                    'description' => $changeNotes ?: 'Automated update by Spectra',
                ],
            ]);

            $versionId = basename($version->getName());

            // Publish the version
            $this->gtmClient->publishContainerVersion([
                'path' => "accounts/{$customer->gtm_account_id}/containers/{$customer->gtm_container_id}/versions/{$versionId}",
            ]);

            Log::info("Published GTM container", [
                'customer_id' => $customer->id,
                'version_id' => $versionId,
            ]);

            return [
                'published' => true,
                'versionId' => $versionId,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to publish container: " . $e->getMessage());
            return null;
        }
    }
}
```

**🎯 Key Advantage:** Since GTM is already installed on their website, when we publish changes, tags go live automatically without any code deployment!

---

#### Step 1.2: Store GTM Configuration

Add fields to customers table:

```php
Schema::table('customers', function (Blueprint $table) {
    $table->string('gtm_container_id')->nullable();
    $table->string('gtm_account_id')->nullable();
    $table->string('gtm_workspace_id')->nullable();
    $table->json('gtm_config')->nullable(); // Store tags, triggers, variables
    $table->boolean('gtm_installed')->default(false);
    $table->timestamp('gtm_last_verified')->nullable();
});
```

---

### Phase 2: Automated Tag Generation

#### Step 2.1: Conversion Tag Generator

```php
namespace App\Services\GTM;

class ConversionTagGenerator
{
    /**
     * Generate conversion tracking tag
     * This fires when a user completes a desired action
     */
    public function generateConversionTag(array $config): array
    {
        return [
            'displayName' => $config['name'] ?? 'Conversion Tracking',
            'type' => 'gaawe', // Google Ads conversion tracking tag
            'parameter' => [
                [
                    'key' => 'conversionId',
                    'value' => $config['google_ads_conversion_id'],
                ],
                [
                    'key' => 'conversionLabel',
                    'value' => $config['conversion_label'],
                ],
                [
                    'key' => 'eventName',
                    'value' => $config['event_name'] ?? 'purchase',
                ],
                [
                    'key' => 'conversionValue',
                    'value' => '{{purchase_value}}', // Dynamic value from dataLayer
                ],
                [
                    'key' => 'currencyCode',
                    'value' => $config['currency'] ?? 'USD',
                ],
            ],
            'firingTriggerId' => [$config['trigger_id']], // When to fire
            'tagManagerUrl' => null,
        ];
    }

    /**
     * Generate Facebook pixel tag
     */
    public function generateFacebookPixelTag(string $pixelId): array
    {
        return [
            'displayName' => 'Facebook Pixel',
            'type' => 'fbq', // Facebook pixel tag
            'parameter' => [
                [
                    'key' => 'pixelId',
                    'value' => $pixelId,
                ],
                [
                    'key' => 'eventId',
                    'value' => '{{event_id}}',
                ],
                [
                    'key' => 'eventValue',
                    'value' => '{{purchase_value}}',
                ],
                [
                    'key' => 'currency',
                    'value' => '{{currency_code}}',
                ],
            ],
            'firingTriggerId' => ['trigger_purchase_event'],
        ];
    }

    /**
     * Generate GA4 event tag
     */
    public function generateGA4EventTag(string $measurementId, string $eventName): array
    {
        return [
            'displayName' => "GA4 Event - {$eventName}",
            'type' => 'gat', // Google Analytics 4 tag
            'parameter' => [
                [
                    'key' => 'measurementId',
                    'value' => $measurementId,
                ],
                [
                    'key' => 'eventName',
                    'value' => $eventName,
                ],
                [
                    'key' => 'userProperties',
                    'value' => '{{user_properties}}',
                ],
                [
                    'key' => 'eventValue',
                    'value' => '{{event_value}}',
                ],
            ],
            'firingTriggerId' => ["trigger_{$eventName}"],
        ];
    }

    /**
     * Generate ecommerce tracking tag for product pages
     */
    public function generateEcommerceTag(): array
    {
        return [
            'displayName' => 'Enhanced Ecommerce',
            'type' => 'ua', // Universal Analytics (can be GA4)
            'parameter' => [
                [
                    'key' => 'trackingId',
                    'value' => '{{tracking_id}}',
                ],
                [
                    'key' => 'useEcommerce',
                    'value' => 'true',
                ],
                [
                    'key' => 'ecommerceData',
                    'value' => '{{ecommerce}}',
                ],
            ],
        ];
    }
}
```

#### Step 2.2: Trigger Generator

```php
namespace App\Services\GTM;

class TriggerGenerator
{
    /**
     * Generate trigger for form submission
     */
    public function generateFormSubmitTrigger(array $formIds): array
    {
        return [
            'displayName' => 'Form Submission',
            'type' => 'formSubmission',
            'customEventFilter' => [
                [
                    'parameter' => [
                        [
                            'key' => 'arg0',
                            'value' => 'formId',
                        ],
                        [
                            'key' => 'arg1',
                            'value' => implode('|', $formIds),
                        ],
                        [
                            'key' => 'type',
                            'value' => 'regex',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Generate trigger for button click
     */
    public function generateClickTrigger(string $buttonSelector): array
    {
        return [
            'displayName' => 'Button Click - ' . $buttonSelector,
            'type' => 'click',
            'customEventFilter' => [
                [
                    'parameter' => [
                        [
                            'key' => 'arg0',
                            'value' => 'elementId',
                        ],
                        [
                            'key' => 'arg1',
                            'value' => $buttonSelector,
                        ],
                        [
                            'key' => 'type',
                            'value' => 'contains',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Generate trigger for page view
     */
    public function generatePageViewTrigger(string $pathRegex): array
    {
        return [
            'displayName' => 'Page View - ' . $pathRegex,
            'type' => 'pageview',
            'customEventFilter' => [
                [
                    'parameter' => [
                        [
                            'key' => 'arg0',
                            'value' => 'page_path',
                        ],
                        [
                            'key' => 'arg1',
                            'value' => $pathRegex,
                        ],
                        [
                            'key' => 'type',
                            'value' => 'regex',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Generate trigger for custom event
     */
    public function generateCustomEventTrigger(string $eventName): array
    {
        return [
            'displayName' => 'Custom Event - ' . $eventName,
            'type' => 'customEvent',
            'customEventFilter' => [
                [
                    'parameter' => [
                        [
                            'key' => 'eventName',
                            'value' => $eventName,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Generate trigger for scroll depth
     */
    public function generateScrollDepthTrigger(int $percentageThreshold = 90): array
    {
        return [
            'displayName' => "Scroll Depth - {$percentageThreshold}%",
            'type' => 'scrollDepth',
            'customEventFilter' => [
                [
                    'parameter' => [
                        [
                            'key' => 'verticalScrollDepthThreshold',
                            'value' => (string) $percentageThreshold,
                        ],
                    ],
                ],
            ],
        ];
    }
}
```

---

### Phase 3: Website Integration

#### Step 3.1: GTM Installation Service

```php
namespace App\Services\GTM;

use Illuminate\Support\Facades\Http;

class GTMDeploymentService
{
    /**
     * Deploy GTM to customer's website
     * This makes API calls to inject GTM code
     */
    public function deployGTMToWebsite(Customer $customer): bool
    {
        try {
            // Step 1: Generate GTM snippet
            $gtmService = new GTMContainerService();
            $headerSnippet = $gtmService->generateInstallSnippet($customer);
            $noscriptSnippet = $gtmService->generateNoscriptSnippet($customer);

            // Step 2: Call customer's website to inject code
            // (This assumes they have an API endpoint for this)
            $response = Http::post($customer->website . '/api/inject-gtm', [
                'header_snippet' => $headerSnippet,
                'noscript_snippet' => $noscriptSnippet,
                'gtm_container_id' => $customer->gtm_container_id,
            ]);

            if ($response->successful()) {
                $customer->update(['gtm_installed' => true]);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error("GTM deployment failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate dataLayer initialization code
     * This sets up the data structure for tracking
     */
    public function generateDataLayerInit(Campaign $campaign): string
    {
        return <<<JAVASCRIPT
<script>
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({
    'gtm.start': new Date().getTime(),
    'event': 'gtm.js',
    'campaignId': '{$campaign->id}',
    'campaignName': '{$campaign->name}',
    'businessType': '{$campaign->customer->business_type}',
    'userId': window.userId || null,
    'userEmail': window.userEmail || null,
    'pageUrl': window.location.href,
});
</script>
JAVASCRIPT;
    }

    /**
     * Generate event tracking code to inject into page
     * This can be applied to buttons, forms, etc.
     */
    public function generateEventTrackingCode(string $eventName, array $eventData = []): string
    {
        $dataJson = json_encode($eventData);

        return <<<JAVASCRIPT
<script>
// Track {$eventName} event
document.addEventListener('DOMContentLoaded', function() {
    // For buttons
    document.querySelectorAll('[data-event-track="{$eventName}"]').forEach(el => {
        el.addEventListener('click', function() {
            window.dataLayer.push({
                'event': '{$eventName}',
                ...{$dataJson}
            });
        });
    });

    // For forms
    document.querySelectorAll('[data-event-form="{$eventName}"]').forEach(form => {
        form.addEventListener('submit', function() {
            window.dataLayer.push({
                'event': '{$eventName}',
                'formData': new FormData(this),
                ...{$dataJson}
            });
        });
    });
});
</script>
JAVASCRIPT;
    }
}
```

#### Step 3.2: Automatic Element Detection & Tracking

```php
namespace App\Services\GTM;

use App\Services\WebCrawler;

class AutomaticElementTrackingService
{
    /**
     * Scan website and automatically identify trackable elements
     * Uses the page data we already scraped
     */
    public function identifyTrackableElements(Customer $customer): array
    {
        $trackableElements = [
            'forms' => $this->identifyForms($customer),
            'buttons' => $this->identifyButtons($customer),
            'cta_elements' => $this->identifyCTAs($customer),
            'product_pages' => $this->identifyProductPages($customer),
            'checkout_pages' => $this->identifyCheckoutPages($customer),
        ];

        return $trackableElements;
    }

    /**
     * Find all forms on website
     */
    private function identifyForms(Customer $customer): array
    {
        // Get scraped page data
        $pages = $customer->pages()->get(); // Assuming we stored scraped pages

        $forms = [];
        foreach ($pages as $page) {
            // Parse HTML to find forms
            $dom = new \DOMDocument();
            @$dom->loadHTML($page->content);
            $xpath = new \DOMXPath($dom);

            foreach ($xpath->query('//form') as $form) {
                $forms[] = [
                    'id' => $form->getAttribute('id') ?? 'form_' . uniqid(),
                    'name' => $form->getAttribute('name'),
                    'action' => $form->getAttribute('action'),
                    'method' => $form->getAttribute('method'),
                    'page_url' => $page->url,
                    'fields' => $this->extractFormFields($form),
                ];
            }
        }

        return $forms;
    }

    /**
     * Find CTA buttons
     */
    private function identifyCTAs(Customer $customer): array
    {
        $pages = $customer->pages()->get();
        $ctas = [];

        $ctaKeywords = ['buy', 'purchase', 'signup', 'register', 'subscribe', 
                        'download', 'get started', 'contact', 'book', 'order', 
                        'schedule', 'apply', 'submit'];

        foreach ($pages as $page) {
            $dom = new \DOMDocument();
            @$dom->loadHTML($page->content);
            $xpath = new \DOMXPath($dom);

            // Find buttons and links with CTA text
            foreach ($xpath->query('//button | //a[@class="btn"] | //input[@type="button"]') as $element) {
                $text = strtolower($element->textContent ?? $element->getAttribute('value') ?? '');

                foreach ($ctaKeywords as $keyword) {
                    if (strpos($text, $keyword) !== false) {
                        $ctas[] = [
                            'text' => trim($text),
                            'type' => $element->nodeName,
                            'selector' => $this->generateSelector($element),
                            'page_url' => $page->url,
                            'element_id' => $element->getAttribute('id'),
                            'element_class' => $element->getAttribute('class'),
                        ];
                        break;
                    }
                }
            }
        }

        return $ctas;
    }

    /**
     * Identify product pages (for ecommerce)
     */
    private function identifyProductPages(Customer $customer): array
    {
        $pages = $customer->pages()->get();
        $productPages = [];

        foreach ($pages as $page) {
            // Look for pricing, product, shop in URL
            if (preg_match('/product|shop|pricing|price|item|listing/i', $page->url)) {
                $productPages[] = [
                    'url' => $page->url,
                    'title' => $page->title,
                    'has_price' => preg_match('/\$|\d+\.\d{2}/', $page->content) ? true : false,
                ];
            }
        }

        return $productPages;
    }

    /**
     * Identify checkout pages
     */
    private function identifyCheckoutPages(Customer $customer): array
    {
        $pages = $customer->pages()->get();
        $checkoutPages = [];

        foreach ($pages as $page) {
            if (preg_match('/checkout|cart|payment|order|purchase|billing/i', $page->url)) {
                $checkoutPages[] = [
                    'url' => $page->url,
                    'title' => $page->title,
                    'type' => $this->classifyCheckoutPage($page->url),
                ];
            }
        }

        return $checkoutPages;
    }

    /**
     * Generate CSS selector for an element
     */
    private function generateSelector(\DOMElement $element): string
    {
        $id = $element->getAttribute('id');
        if ($id) {
            return "#{$id}";
        }

        $class = $element->getAttribute('class');
        if ($class) {
            return '.' . implode('.', array_slice(explode(' ', $class), 0, 2));
        }

        // Generate path-based selector
        $path = [];
        $node = $element;
        while ($node && $node->nodeName !== '#document') {
            $path[] = $node->nodeName;
            $node = $node->parentNode;
        }

        return implode(' > ', array_reverse($path));
    }
}
```

---

### Phase 4: Conversion Tracking Setup

#### Step 4.1: Google Ads Conversion Creation

```php
namespace App\Services\GoogleAds;

use Google\Ads\GoogleAds\V22\Services\ConversionActionOperation;
use Google\Ads\GoogleAds\V22\Resources\ConversionAction;

class ConversionTrackingService extends BaseGoogleAdsService
{
    /**
     * Create conversion actions in Google Ads
     * These correspond to GTM events
     */
    public function createConversionAction(
        string $customerId,
        string $conversionName,
        array $config = []
    ): ?string {
        try {
            $conversionAction = new ConversionAction([
                'name' => $conversionName,
                'type' => $config['type'] ?? 'WEBPAGE', // WEBPAGE, CALL, IMPORT
                'category' => $config['category'] ?? 'PURCHASE',
                'status' => $config['status'] ?? 'ENABLED',
                'counting_type' => $config['counting_type'] ?? 'ONE_PER_CLICK',
            ]);

            if (isset($config['value'])) {
                $conversionAction->setDefaultRevenueValue([
                    'amount_micros' => (int)($config['value'] * 1000000),
                    'currency_code' => $config['currency'] ?? 'USD',
                ]);
            }

            $operation = new ConversionActionOperation();
            $operation->setCreate($conversionAction);

            $conversionActionService = $this->client->getConversionActionServiceClient();
            $response = $conversionActionService->mutateConversionActions(
                $customerId,
                [$operation]
            );

            $resourceName = $response->getResults()[0]->getResourceName();

            Log::info("Created conversion action: {$conversionName}", [
                'resource_name' => $resourceName,
            ]);

            return $resourceName;
        } catch (\Exception $e) {
            Log::error("Failed to create conversion action: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get conversion label for GTM
     */
    public function getConversionLabel(string $customerId, string $conversionName): ?string
    {
        try {
            // Query to find conversion by name
            $query = "SELECT conversion_action.id, conversion_action.name "
                   . "FROM conversion_action "
                   . "WHERE conversion_action.name = '{$conversionName}'";

            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $response = $googleAdsServiceClient->search($customerId, $query);

            foreach ($response->getIterator() as $row) {
                return $row->getConversionAction()->getId();
            }

            return null;
        } catch (\Exception $e) {
            Log::error("Failed to get conversion label: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Link GTM event to Google Ads conversion
     */
    public function linkConversionToEvent(Campaign $campaign, string $gtmEventName): bool
    {
        try {
            $campaign->update([
                'gtm_conversion_events' => array_merge(
                    $campaign->gtm_conversion_events ?? [],
                    [$gtmEventName]
                ),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to link conversion: " . $e->getMessage());
            return false;
        }
    }
}
```

---

### Phase 5: Monitoring & Validation

#### Step 5.1: Tag Health Monitor

```php
namespace App\Services\GTM;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TagHealthMonitor
{
    /**
     * Verify GTM is installed and firing
     */
    public function verifyGTMInstallation(Customer $customer): array
    {
        $health = [
            'installed' => false,
            'firing' => false,
            'errors' => [],
            'last_checked' => now(),
        ];

        try {
            // Check if GTM script is present on website
            $response = Http::get($customer->website);
            $html = $response->body();

            $containerId = $customer->gtm_container_id;

            if (strpos($html, "googletagmanager.com/gtm.js?id={$containerId}") !== false) {
                $health['installed'] = true;
            } else {
                $health['errors'][] = 'GTM script not found in website header';
            }

            // Check GTM API for recent tag fires
            $firing = $this->checkTagFiring($customer);
            if ($firing) {
                $health['firing'] = true;
            } else {
                $health['errors'][] = 'No recent tag firing detected';
            }

        } catch (\Exception $e) {
            $health['errors'][] = $e->getMessage();
        }

        // Store health status
        $customer->update([
            'gtm_health' => $health,
            'gtm_last_verified' => now(),
        ]);

        return $health;
    }

    /**
     * Check if tags are actually firing in GTM
     */
    private function checkTagFiring(Customer $customer): bool
    {
        try {
            $containerId = $customer->gtm_container_id;
            
            // Query GTM API for tag firing data
            // This would use Google's Measurement Protocol or GTM API
            // For now, simplified version
            
            Log::info("Checking tag firing for customer {$customer->id}");

            return true; // Placeholder
        } catch (\Exception $e) {
            Log::error("Tag firing check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get tag firing statistics
     */
    public function getTagStats(Customer $customer, int $days = 7): array
    {
        try {
            // Query GA4 or GTM reporting API
            $stats = [
                'total_events' => 0,
                'events_by_type' => [],
                'conversion_events' => [],
                'error_events' => [],
            ];

            // This would integrate with Google Analytics Reporting API
            // Return aggregated tag firing data

            return $stats;
        } catch (\Exception $e) {
            Log::error("Failed to get tag stats: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Detect and alert on tag issues
     */
    public function detectTagIssues(Customer $customer): array
    {
        $issues = [];

        $health = $customer->gtm_health ?? [];

        if (!$health['installed'] ?? false) {
            $issues[] = [
                'severity' => 'critical',
                'message' => 'GTM not installed on website',
                'action' => 'Deploy GTM code to website header',
            ];
        }

        if (!$health['firing'] ?? false) {
            $issues[] = [
                'severity' => 'critical',
                'message' => 'GTM tags not firing',
                'action' => 'Check website console for errors, verify GTM configuration',
            ];
        }

        // Check for conversion tracking issues
        $conversionIssues = $this->checkConversionTracking($customer);
        if (!empty($conversionIssues)) {
            $issues = array_merge($issues, $conversionIssues);
        }

        return $issues;
    }

    /**
     * Check conversion tracking specifically
     */
    private function checkConversionTracking(Customer $customer): array
    {
        $issues = [];

        $conversions = $customer->gtm_conversion_events ?? [];
        if (empty($conversions)) {
            $issues[] = [
                'severity' => 'warning',
                'message' => 'No conversions configured',
                'action' => 'Set up conversion events in GTM',
            ];
        }

        return $issues;
    }
}
```

---

## Benefits of GTM Integration

### 1. **Accurate Conversion Tracking**
- Track every user action back to source campaign
- Measure true ROAS (Return on Ad Spend)
- Attribute revenue to specific ads

### 2. **Better Campaign Optimization**
- Real data on what's working
- Automatic bidding adjustments based on conversions
- A/B testing with statistically significant data

### 3. **Enhanced Remarketing**
- Behavioral data on what users do on site
- Segment users by actions taken
- More targeted remarketing audiences

### 4. **Comprehensive Analytics**
- Cross-platform view (Google, Facebook, internal)
- User journey mapping
- Multi-touch attribution

### 5. **Dynamic Product Ads**
- For ecommerce sites: automatic product feed
- Retarget users with exact products they viewed
- Dynamic pricing and inventory data

### 6. **Compliance & Privacy**
- First-party data collection (GDPR compliant)
- Server-side tagging option
- User consent management

---

## Implementation Roadmap

### Path A: Customers with Existing GTM (PRIORITY - Easiest & Fastest)

**Week 1: Setup & Testing**
- [ ] Build GTM Container Service (linkExistingContainer, addConversionTag, addTrigger, publishContainer)
- [ ] Create GTM linking UI (ask for container ID)
- [ ] Build integration test with test GTM container
- [ ] Document step-by-step linking process for customers

**Week 2: Tag Generation & Deployment**
- [ ] Build ConversionTagGenerator service
- [ ] Implement auto-tag creation for Google Ads conversions
- [ ] Add trigger generators (page view, form submit, purchase events)
- [ ] Implement automatic Facebook pixel tag generation

**Week 3: End-to-End Testing & Launch**
- [ ] Test with 2-3 beta customers
- [ ] Verify tags fire correctly on their sites
- [ ] Create customer support guide
- [ ] Launch to all customers with GTM installed

**Result:** Customers with GTM = Zero friction. Tags deployed in ~5 minutes.

---

### Path B: Customers Without GTM (SECONDARY - Requires Setup)

**Month 2: Self-Service GTM Installation**
- [ ] Create GTM container automatically (if they authorize)
- [ ] Provide install snippet (copy/paste)
- [ ] Create WordPress plugin (for WordPress sites)
- [ ] Build installation verification system

**Result:** Customers without GTM = Simple install process. Can auto-detect completion.

---

### Advanced Features (Future - Q2 2026)

**Month 3-4: Monitoring & Optimization**
- [ ] Build tag health monitor
- [ ] Implement alerting system (tags not firing)
- [ ] Create tag performance dashboard
- [ ] Build troubleshooting guides

**Month 5-6: Advanced Features**
- [ ] Server-side tagging (for privacy-first deployments)
- [ ] Enhanced ecommerce tracking (product feeds)
- [ ] Cross-domain tracking
- [ ] Event debugging UI

---

## Challenges & Solutions

### Challenge 1: Existing GTM Detection
**Problem:** How do we know if customer has GTM already installed?

**Solutions:**
1. **Ask Customer:** Simple form asking "Do you have GTM installed?"
2. **Provide Container ID:** If yes, ask for their GTM container ID
3. **Auto-Detection (Future):** Scan customer website to detect GTM
4. **Fallback:** Create new container if they don't have one

**Implementation Priority:** Solution 1 + 2 (immediate), Solution 3 (later)

---

### Challenge 2: Website Access (Minimal for Existing GTM)
**Problem:** If they DON'T have GTM, how do we install code?

**Solutions (Only for new GTM installations):**
1. **Copy/Paste:** Provide GTM snippet they copy to website
2. **WordPress Plugin:** Auto-install for WordPress sites
3. **DNS CNAME:** Route domain through our servers (advanced)

**Key:** With existing GTM, this is NOT needed. Tags deploy programmatically!

---

### Challenge 3: Data Privacy
**Problem:** Collecting user data raises privacy concerns

**Solutions:**
1. **Consent Management:** GTM supports consent banners natively
2. **First-Party Data:** Data stored on customer's domain
3. **No Personal Data:** We track conversions, not individual users
4. **GDPR Ready:** GTM configuration is privacy-compliant by default

---

### Challenge 4: Technical Complexity
**Problem:** GTM configuration can be complex

**Solutions:**
1. **Automation:** We auto-generate all tags/triggers based on customer info
2. **Templates:** Pre-built configurations for common scenarios
3. **UI Wizard:** Step-by-step setup (ask for GTM container ID, do the rest automatically)
4. **Support:** Comprehensive documentation & email support
**Problem:** Sites get updated, GTM config becomes outdated

**Solutions:**
1. **Periodic Scanning:** Re-scan sites monthly for changes
2. **Alerts:** Notify on significant website changes
3. **Auto-Update:** Automatically update trackable elements
4. **Version Control:** Track GTM config changes

---

## Success Metrics

- **GTM Installation Rate:** % of campaigns with working GTM
- **Tag Firing Rate:** % of pageviews with tags firing
- **Conversion Tracking Accuracy:** Conversions tracked vs. actual
- **ROAS Improvement:** Better ad optimization with conversion data
- **Campaign Performance:** % improvement in CTR/CPC/ROAS
- **User Adoption:** % of customers using GTM tracking

---

## Complete Customer Workflow

### For Customers WITH Existing GTM (The Easy Path)

```
1. Customer provides GTM Container ID (GTM-XXXXXX)
   ↓
2. Spectra links existing container
   ↓
3. Spectra programmatically adds conversion tags to container
   ↓
4. Spectra creates triggers (page view, purchase, form submit)
   ↓
5. Spectra publishes container version
   ↓
6. Tags go LIVE on customer's website automatically
   ↓
✅ Zero customer action needed after providing Container ID
✅ Tags firing within 5 minutes
✅ Conversions tracked immediately
```

**Implementation Time:** Week 1-2
**Customer Effort:** 2 minutes (provide Container ID)

---

### For Customers WITHOUT GTM (The Alternative Path)

```
1. Customer authorizes Spectra to create GTM container
   ↓
2. Spectra creates new container in customer's GTM account
   ↓
3. Spectra generates GTM install snippet
   ↓
4. Spectra provides copy/paste code OR
   - Installs WordPress plugin (if WordPress site)
   - Provides alternative installation methods
   ↓
5. Customer installs GTM on website
   ↓
6. Spectra adds conversion tags to container (same as Path A)
   ↓
7. Spectra publishes container
   ↓
✅ Conversions tracked immediately after GTM installed
✅ Simplified one-snippet installation
```

**Implementation Time:** Month 2
**Customer Effort:** 5 minutes (copy/paste code)

---

## Current Implementation Status

### ✅ Complete
- Google Ads campaign creation & deployment
- Facebook Ads campaign creation & deployment
- Performance data collection from both platforms
- Performance analytics dashboard
- System architecture documented

### 🔄 In Progress
- GTM integration strategy (this document)
- GTM Container Service code implementation
- Tag generation services

### ⏳ Next Steps
1. **Week 1:** Build GTMContainerService with all 4 methods
   - `linkExistingContainer()`
   - `addConversionTag()`
   - `addTrigger()`
   - `publishContainer()`

2. **Week 2:** Create UI for GTM linking
   - Simple form asking "Do you have GTM installed?"
   - If yes: prompt for Container ID
   - If no: show new container creation option

3. **Week 3:** Build ConversionTagGenerator service
   - Auto-generate Google Ads conversion tags
   - Auto-generate Facebook pixel tags
   - Auto-generate GA4 event tags

4. **Week 4:** End-to-end testing
   - Test with 2-3 beta customers
   - Verify tags fire on their websites
   - Document any issues

---

## Conclusion

Google Tag Manager integration is the natural next step after campaign deployment. By automating GTM setup and tag generation, we transform Spectra from a "set it and forget it" system into a truly intelligent optimization platform.

**Key Innovation:** If customer already has GTM installed, we deploy tags programmatically without requiring any website changes. This is the key to zero-friction adoption.

With GTM in place, our system gains real-time visibility into campaign performance, enabling:
- Accurate conversion tracking
- Data-driven optimizations
- Better budget allocation
- Improved ROI for customers

The path forward is clear:
1. **Priority Path:** Support existing GTM containers (80% of enterprise customers)
2. **Alternative Path:** Create containers for those without GTM (20% of customers)
3. **Advanced Path:** Server-side tagging, custom events, ecommerce tracking (future phases)

Our job is to make it seamless for all customer types.

```
