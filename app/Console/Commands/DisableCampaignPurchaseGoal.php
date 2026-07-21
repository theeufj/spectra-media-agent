<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Google\Ads\GoogleAds\V22\Resources\CampaignConversionGoal;
use Google\Ads\GoogleAds\V22\Services\CampaignConversionGoalOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignConversionGoalsRequest;
use Google\Protobuf\FieldMask;
use Illuminate\Console\Command;

/**
 * Stop a campaign optimising toward a conversion-goal category that has no primary
 * conversion action (e.g. PURCHASE on a signup-only SaaS) by setting that campaign
 * conversion goal to non-biddable. Fixes Google's "targeted goal is missing a primary
 * conversion action" diagnostic. Dry-run by default; --apply to mutate.
 */
class DisableCampaignPurchaseGoal extends Command
{
    protected $signature = 'conversions:disable-goal {campaign : Laravel campaign id}
        {--category=PURCHASE : Conversion-goal category to make non-biddable}
        {--apply : Actually mutate (otherwise dry-run)}';

    protected $description = 'Set a campaign conversion goal (default PURCHASE) to non-biddable.';

    public function handle(): int
    {
        $campaign = Campaign::find($this->argument('campaign'));
        if (!$campaign || !$campaign->google_ads_campaign_id) {
            $this->error('Campaign not found or not on Google Ads.');
            return 1;
        }

        $categoryName = strtoupper($this->option('category'));
        $categoryVal  = ConversionActionCategory::value($categoryName);
        $customer     = $campaign->customer;
        $customerId   = $customer->cleanGoogleCustomerId();
        $campaignId   = $campaign->googleCampaignNumericId();

        $svc = new class($customer) extends BaseGoogleAdsService {
            public function goals(string $cid, string $campId): array
            {
                $this->ensureClient();
                $rows = [];
                $q = "SELECT campaign_conversion_goal.resource_name, campaign_conversion_goal.category, "
                    . "campaign_conversion_goal.biddable FROM campaign_conversion_goal WHERE campaign.id = {$campId}";
                foreach ($this->searchQuery($cid, $q)->iterateAllElements() as $row) {
                    $g = $row->getCampaignConversionGoal();
                    $rows[] = ['resource' => $g->getResourceName(), 'category' => $g->getCategory(), 'biddable' => $g->getBiddable()];
                }
                return $rows;
            }

            public function mutate(string $cid, array $ops): int
            {
                return count($this->client->getCampaignConversionGoalServiceClient()
                    ->mutateCampaignConversionGoals(new MutateCampaignConversionGoalsRequest([
                        'customer_id' => $cid,
                        'operations'  => $ops,
                    ]))->getResults());
            }
        };

        $target = null;
        foreach ($svc->goals($customerId, $campaignId) as $g) {
            $name = ConversionActionCategory::name($g['category']);
            $this->line(sprintf('%s %s (biddable=%s)', $g['category'] === $categoryVal ? '~' : ' ', $name, $g['biddable'] ? 'yes' : 'no'));
            if ($g['category'] === $categoryVal) {
                $target = $g;
            }
        }

        if (!$target) {
            $this->info("Campaign does not target the {$categoryName} goal — nothing to do.");
            return 0;
        }
        if (!$target['biddable']) {
            $this->info("{$categoryName} goal is already non-biddable.");
            return 0;
        }

        if (!$this->option('apply')) {
            $this->warn("Would set {$categoryName} to non-biddable. Re-run with --apply.");
            return 0;
        }

        $goal = new CampaignConversionGoal(['resource_name' => $target['resource'], 'biddable' => false]);
        $op = new CampaignConversionGoalOperation();
        $op->setUpdate($goal);
        $op->setUpdateMask(new FieldMask(['paths' => ['biddable']]));

        $n = $svc->mutate($customerId, [$op]);
        $this->info("Set {$categoryName} goal to non-biddable ({$n} change).");
        return 0;
    }
}
