<?php

namespace App\Services\GTM;

use Illuminate\Support\Facades\Log;

class GTMDetectionService
{
    /**
     * Detect if GTM is installed on a website and extract the container ID.
     * 
     * Looks for the Google Tag Manager script tag pattern:
     * https://www.googletagmanager.com/gtm.js?id=GTM-XXXXXX
     *
     * @param string $htmlContent The HTML content of the webpage
     * @return string|null The GTM container ID (e.g., "GTM-ABCD1234") or null if not found
     */
    public function detectGTMContainer(string $htmlContent): ?string
    {
        try {
            // Match either the head script (gtm.js?id=) or the noscript body
            // (ns.html?id=). The standard GTM snippet builds the gtm.js URL in
            // JavaScript ('...gtm.js?id='+i), so in raw (unrendered) HTML only the
            // ns.html?id= form is literally present — matching only gtm.js?id=
            // fails to detect a correctly installed standard snippet.
            $pattern = '/googletagmanager\.com\/(?:gtm\.js|ns\.html)\?id=([A-Z0-9\-]+)/i';

            if (preg_match($pattern, $htmlContent, $matches)) {
                $containerId = $matches[1];
                
                // Validate GTM container ID format (GTM-XXXXXX)
                if ($this->isValidContainerId($containerId)) {
                    Log::info('GTM detected', [
                        'container_id' => $containerId,
                        'detection_service' => 'GTMDetectionService',
                    ]);
                    
                    return $containerId;
                }
            }
            
            Log::debug('GTM not detected in HTML content');
            return null;
        } catch (\Exception $e) {
            Log::error('Error detecting GTM container', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return null;
        }
    }

    /**
     * Detect ALL GTM/GT container IDs present on the page (head gtm.js and
     * noscript ns.html forms). A site can legitimately run multiple containers,
     * so to confirm a *specific* container is installed, check membership of this
     * list rather than equality with detectGTMContainer()'s single first match.
     *
     * @param string $htmlContent The HTML content of the webpage
     * @return string[] Unique, valid container IDs in first-seen order
     */
    public function detectAllContainers(string $htmlContent): array
    {
        $ids = [];
        $pattern = '/googletagmanager\.com\/(?:gtm\.js|ns\.html)\?id=([A-Z0-9\-]+)/i';

        if (preg_match_all($pattern, $htmlContent, $matches)) {
            foreach ($matches[1] as $id) {
                $id = strtoupper($id);
                if ($this->isValidContainerId($id) && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Validate that a container ID is in the correct format.
     *
     * @param string $containerId The container ID to validate
     * @return bool True if valid format, false otherwise
     */
    private function isValidContainerId(string $containerId): bool
    {
        // GTM container IDs follow pattern: GTM-XXXXXX
        // Can also be: GT-XXXXXX for GA-only containers
        return preg_match('/^(GTM|GT)-[A-Z0-9]+$/', $containerId) === 1;
    }

    /**
     * Extract all GTM script tags from HTML (for debugging/analysis).
     *
     * @param string $htmlContent The HTML content
     * @return array Array of found GTM script segments
     */
    public function extractGTMScriptTags(string $htmlContent): array
    {
        $scripts = [];
        
        // Match the entire Google Tag Manager noscript fallback and script tag
        $pattern = '/<noscript>.*?<iframe[^>]*src="[^"]*googletagmanager\.com\/ns\.html\?id=([A-Z0-9\-]+)"[^>]*><\/iframe>.*?<\/noscript>|<script>.*?googletagmanager\.com\/gtm\.js\?id=([A-Z0-9\-]+).*?<\/script>/is';
        
        if (preg_match_all($pattern, $htmlContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $scripts[] = $match[0];
            }
        }
        
        return $scripts;
    }

    /**
     * Check if GTM is installed and return detection metadata.
     *
     * @param string $htmlContent The HTML content
     * @return array Array with 'detected', 'container_id', 'detected_at', and 'script_count'
     */
    public function getDetectionMetadata(string $htmlContent): array
    {
        $containerId = $this->detectGTMContainer($htmlContent);
        $scripts = $this->extractGTMScriptTags($htmlContent);
        
        return [
            'detected' => $containerId !== null,
            'container_id' => $containerId,
            'detected_at' => now(),
            'script_count' => count($scripts),
            'scripts' => $scripts,
        ];
    }
}
