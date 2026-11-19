<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Strategy;

class CampaignTrackingUrl
{
    public static function forStrategy(string $url, Campaign $campaign, Strategy $strategy, string $platform, ?string $offer = null): string
    {
        [$url, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$path, $query] = array_pad(explode('?', $url, 2), 2, '');

        $tracking = [
            'utm_source' => $platform,
            'utm_medium' => $strategy->campaign_type ?? ($platform === 'facebook' ? 'social' : 'cpc'),
            'utm_campaign' => 'spectra_'.$campaign->id,
            'utm_content' => 'strategy_'.$strategy->id.($offer === null ? '' : '_offer_'.$offer),
        ];

        $parameters = array_filter(explode('&', $query), static function (string $parameter) use ($tracking): bool {
            $key = urldecode(explode('=', $parameter, 2)[0]);

            return $parameter !== '' && ! array_key_exists($key, $tracking);
        });
        $parameters[] = http_build_query($tracking);

        return $path.'?'.implode('&', $parameters).($fragment === null ? '' : '#'.$fragment);
    }
}
