<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\GoogleAds\ActivatePaidSubscriptionGoal;
use App\Services\GoogleAds\DataManagerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Run only following explicit approval to change this campaign's bidding goals. */
class ActivateSitePaidSubscriptionGoal implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 8;

    public $backoff = [600, 1800, 3600, 7200, 10800, 21600, 21600];

    public $timeout = 180;

    public $uniqueFor = 172800;

    public $deleteWhenMissingModels = true;

    public function __construct(public Campaign $campaign) {}

    public function uniqueId(): string
    {
        return (string) $this->campaign->id;
    }

    public function handle(DataManagerService $dataManager): void
    {
        (new ActivatePaidSubscriptionGoal($this->campaign->customer))->activate($this->campaign, $dataManager);
    }
}
