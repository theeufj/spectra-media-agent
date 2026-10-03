<?php

namespace App\Services\Attribution;

use App\Models\AttributionConversion;
use App\Models\AttributionTouchpoint;
use App\Models\Customer;

class AttributionSetup
{
    /** @return array<string, string|null> */
    public function forCustomer(Customer $customer): array
    {
        $websiteHost = parse_url((string) $customer->website, PHP_URL_HOST);
        $scriptPath = public_path('js/spectra-pixel.js');
        $version = is_file($scriptPath) ? filemtime($scriptPath) : 1;
        $scriptUrl = rtrim((string) config('app.url'), '/').'/js/spectra-pixel.js?v='.$version;
        $host = is_string($websiteHost) ? preg_replace('/^www\./i', '', strtolower($websiteHost)) : null;
        $gtmSnippet = null;

        if ($host) {
            // GTM rewrites external <script> tags and drops custom data-* attributes.
            // Create the element in JavaScript so the pixel can read its public site ID.
            $hostJs = json_encode($host, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $siteIdJs = json_encode($customer->uuid, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $scriptUrlJs = json_encode($scriptUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $gtmSnippet = <<<HTML
<script>
(function () {
  var host = location.hostname.toLowerCase();
  var target = {$hostJs};
  if (host !== target && !host.endsWith('.' + target)) return;
  var siteId = {$siteIdJs};
  if (document.querySelector('script[src*="/js/spectra-pixel.js"][data-site-id="' + siteId + '"]')) return;
  var pixel = document.createElement('script');
  pixel.src = {$scriptUrlJs};
  pixel.setAttribute('data-site-id', siteId);
  pixel.async = true;
  document.head.appendChild(pixel);
})();
</script>
HTML;
        }

        return [
            'website_host' => is_string($websiteHost) ? $websiteHost : null,
            'snippet' => $websiteHost ? '<script src="'.$scriptUrl.'" data-site-id="'.$customer->uuid.'" defer></script>' : null,
            'gtm_snippet' => $gtmSnippet,
            'last_visit_at' => AttributionTouchpoint::forCustomer($customer->id)->max('touched_at'),
            'last_conversion_at' => AttributionConversion::forCustomer($customer->id)->max('created_at'),
        ];
    }
}
