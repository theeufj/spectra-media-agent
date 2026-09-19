<?php

namespace App\Services\Campaigns;

use App\Jobs\GenerateAdCopy;
use App\Jobs\GenerateImage;
use App\Jobs\GenerateVideo;
use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\VideoCollateral;

/** The shared generation plan for both campaign and individual sign-off. */
class CollateralPlan
{
    public function wantsVideo(Campaign $campaign, Strategy $strategy): bool
    {
        if (! $campaign->allowsAutomaticVideo()) {
            return false;
        }
        if ($strategy->getRawOriginal('generate_video') !== null) {
            return $strategy->generate_video;
        }

        return self::legacyVideoBrief($strategy->video_strategy ?? '');
    }

    public static function legacyVideoBrief(string $text): bool
    {
        $text = trim($text);

        return $text !== '' && ! preg_match('/^(?:n\/a|none|not applicable)\b|(?:pure |for )?search campaigns?.*(?:text.ads.only|not applicable)/i', $text);
    }

    /** @return list<GenerateAdCopy|GenerateImage|GenerateVideo> */
    public function forStrategy(Campaign $campaign, Strategy $strategy): array
    {
        if (! $strategy->signed_off_at) {
            return [];
        }
        $jobs = [(new GenerateAdCopy($campaign, $strategy, $strategy->platform))->delay(now()->addSeconds(5))];
        if (ImageCollateral::canGenerateForCampaign($campaign)) {
            for ($slot = 0; $slot < 3; $slot++) {
                $jobs[] = (new GenerateImage($campaign, $strategy, $slot))->delay(now()->addSeconds(10 + $slot * 10));
            }
        }
        // Select the first eligible strategy, not the first Search strategy.
        $owner = $campaign->strategies()->whereNotNull('signed_off_at')->orderBy('id')->get()
            ->first(fn (Strategy $candidate) => $this->wantsVideo($campaign, $candidate));
        if ($owner?->id === $strategy->id) {
            $concepts = min(max(1, (int) config('ai.video_concepts_per_campaign', 2)), intdiv(VideoCollateral::remainingForCampaign($campaign), 2));
            for ($concept = 0; $concept < $concepts; $concept++) {
                foreach (['Google Ads (Performance Max)', 'Facebook Ads'] as $shape => $platform) {
                    $jobs[] = (new GenerateVideo($campaign, $strategy, $platform, $concept))
                        ->delay(now()->addSeconds(120 + ($strategy->id % 8) * 45 + $concept * 480 + $shape * 240));
                }
            }
        }

        return $jobs;
    }
}
