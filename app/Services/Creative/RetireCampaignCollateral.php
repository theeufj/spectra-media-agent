<?php

namespace App\Services\Creative;

use App\Jobs\DeleteCollateralFiles;
use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\VideoCollateral;

class RetireCampaignCollateral
{
    /** Called inside the generation swap transaction; storage cleanup runs after commit. */
    public function retire(Campaign $campaign): void
    {
        $paths = [];
        foreach (ImageCollateral::where('campaign_id', $campaign->id)->get() as $image) {
            if ($image->source === 'uploaded') {
                $image->update(['strategy_id' => null]);

                continue;
            }
            if ($image->s3_path) {
                $paths[] = $image->s3_path;
            }
            $image->delete();
        }
        foreach (VideoCollateral::where('campaign_id', $campaign->id)->get() as $video) {
            if ($video->source === 'uploaded') {
                $video->update(['strategy_id' => null]);

                continue;
            }
            if ($video->s3_path) {
                $paths[] = $video->s3_path;
            }
            $video->delete();
        }
        if ($paths !== []) {
            DeleteCollateralFiles::dispatch($paths)->afterCommit();
        }
    }
}
