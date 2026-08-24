<?php

namespace App\Services\VideoGeneration;

use App\Mail\VideosGenerated;
use App\Models\VideoCollateral;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Everything that happens once a video is genuinely finished — narration
 * complete, audio finalized: the "videos ready" email and the PMax
 * length/linking flow.
 *
 * Extracted from CheckVideoStatus so FinalizeVideoNarration can run the same
 * steps after it swaps the audio track.
 */
class VideoPostCompletion
{
    public function __invoke(VideoCollateral $video): void
    {
        $campaign = $video->campaign()->first();
        if (! $campaign instanceof \App\Models\Campaign || ! $campaign->customer) {
            return;
        }

        $videoCount = $campaign->videoCollaterals()->where('status', 'completed')->count();
        foreach ($campaign->customer->users as $user) {
            Mail::to($user->email)->send(new VideosGenerated($user, $campaign, $videoCount));
        }

        // PMax video flow: an 8s Veo clip is too short for PMax (min 10s), so extend
        // the original once (~15s) before linking. An already-extended clip links
        // straight to the asset group to lift ad strength.
        $isPmax = str_contains(strtolower($video->platform ?? ''), 'performance max')
            && $campaign->google_ads_campaign_id;

        if ($isPmax) {
            if (($video->extension_count ?? 0) < 1) {
                \App\Jobs\ExtendPMaxVideo::dispatch($video)->delay(now()->addSeconds(20));
            } else {
                $strategyId = $video->strategy_id ?? $campaign->strategies()->latest()->value('id');
                if ($strategyId) {
                    \App\Jobs\UploadPMaxVideoAssets::dispatch($strategyId, $campaign->customer->cleanGoogleCustomerId())
                        ->delay(now()->addSeconds(30));
                }
            }
        }

        Log::info("VideoPostCompletion: finished for VideoCollateral {$video->id}");
    }
}
