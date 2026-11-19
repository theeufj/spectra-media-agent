<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Campaigns\AdvertisingEvidence;

/** Select distinct, customer-owned destinations for Google campaign sitelinks. */
class SitelinkCoverage
{
    public function __construct(private AdvertisingEvidence $evidence) {}

    public static function target(Strategy $strategy): int
    {
        return $strategy->campaign_type === 'performance_max' ? 6 : 4;
    }

    /** @return array{urls: array<string, bool>, labels: array<string, bool>, count: int} */
    public function inventory(array $existing): array
    {
        $urls = $labels = [];
        $count = 0;
        foreach ($existing as $row) {
            $label = mb_strtolower(trim($row['asset']['sitelinkAsset']['linkText'] ?? ''));
            $destinations = array_filter(array_map($this->evidence->url(...), $row['asset']['finalUrls'] ?? []));
            if ($destinations && ! array_intersect_key(array_flip($destinations), $urls) && ($label === '' || ! isset($labels[$label]))) {
                $count++;
            }
            $urls += array_fill_keys($destinations, true);
            if ($label !== '') {
                $labels[$label] = true;
            }
        }

        return ['urls' => $urls, 'labels' => $labels, 'count' => $count];
    }

    public function plan(Customer $customer, Strategy $strategy, array $existing, ?int $additional = null): array
    {
        ['urls' => $urls, 'labels' => $labels, 'count' => $count] = $this->inventory($existing);
        $needed = $additional ?? max(0, self::target($strategy) - $count);
        $links = [];
        if ($needed > 0) {
            $landing = $strategy->bidding_strategy['landing_page_url'] ?? $strategy->campaign->landing_page_url ?? $customer->website;
            $urls[$this->evidence->url((string) $landing)] = true;
            $proposed = $strategy->ad_extensions['sitelinks'] ?? $strategy->bidding_strategy['sitelinks'] ?? [];
            foreach ($this->evidence->sitelinks($customer, $proposed, 30) as $link) {
                $label = mb_strtolower($link['text']);
                if (isset($urls[$link['url']]) || isset($labels[$label])) {
                    continue;
                }
                $urls[$link['url']] = $labels[$label] = true;
                $links[] = $link;
                if (count($links) >= $needed) {
                    break;
                }
            }
        }

        return ['existing' => $count, 'needed' => $needed, 'links' => $links];
    }
}
