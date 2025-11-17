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
            // Pattern matches GTM container ID in Google Tag Manager script
            // Handles variations in script formatting and attributes
            $pattern = '/googletagmanager\.com\/gtm\.js\?id=([A-Z0-9\-]+)/i';
            
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
