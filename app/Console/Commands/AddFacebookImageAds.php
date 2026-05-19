<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\FacebookAds\AdService;
use App\Services\FacebookAds\CreativeService;
use App\Helpers\StorageHelper;
use Illuminate\Console\Command;

class AddFacebookImageAds extends Command
{
    protected $signature = 'fb:add-image-ads {campaign_id} {strategy_id} {adset_id}';
    protected $description = 'Add all image ads to an existing Facebook ad set';

    public function handle(): void
    {
        $campaign = Campaign::findOrFail($this->argument('campaign_id'));
        $strategy = Strategy::findOrFail($this->argument('strategy_id'));
        $adSetId  = $this->argument('adset_id');
        $customer = $campaign->customer;

        $creativeSvc = new CreativeService($customer);
        $adSvc       = new AdService($customer);
        $accountId   = str_replace('act_', '', $customer->facebook_ads_account_id);

        $adCopy = $strategy->adCopies()->whereRaw('LOWER(platform) LIKE ?', ['%facebook%'])->first();
        $headline    = $adCopy->headlines[0] ?? 'Learn More';
        $primaryText = $adCopy->descriptions[0] ?? 'Discover our products and services';
        $destUrl     = 'https://sitetospend.com.au/?utm_source=facebook&utm_medium=display&utm_campaign=spectra_' . $campaign->id . '&utm_content=strategy_' . $strategy->id;

        $images = $strategy->imageCollaterals()
            ->where('is_active', true)
            ->where('should_deploy', true)
            ->get();

        $this->info("Deploying {$images->count()} images to adset {$adSetId}");

        $created = 0;
        $failed  = 0;

        foreach ($images as $image) {
            try {
                $imageUrl = StorageHelper::url($image->s3_path);
                $creative = $creativeSvc->createImageCreative(
                    $accountId,
                    $campaign->name . ' - Image ' . $image->id,
                    $imageUrl,
                    $headline,
                    $primaryText,
                    'LEARN_MORE',
                    $destUrl
                );
                if (!$creative || !isset($creative['id'])) {
                    $this->warn("SKIP creative for image {$image->id}");
                    $failed++;
                    continue;
                }
                $ad = $adSvc->createAd(
                    $accountId,
                    $adSetId,
                    $campaign->name . ' - Image Ad ' . $image->id,
                    $creative['id']
                );
                if ($ad && isset($ad['id'])) {
                    $this->line("OK  ad={$ad['id']}  img={$image->id}  fmt={$image->format}");
                    $created++;
                } else {
                    $this->warn("FAIL ad for image {$image->id}");
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->error("ERR image {$image->id}: " . $e->getMessage());
                $failed++;
            }
        }

        $this->info("Done. Created={$created} Failed={$failed}");
    }
}
