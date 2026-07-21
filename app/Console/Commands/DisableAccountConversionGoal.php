<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Google\Ads\GoogleAds\V22\Resources\CustomerConversionGoal;
use Google\Ads\GoogleAds\V22\Services\CustomerConversionGoalOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCustomerConversionGoalsRequest;
use Google\Protobuf\FieldMask;
use Illuminate\Console\Command;

/**
 * Make an account-level conversion goal non-biddable so NO campaign optimises toward it
 * (e.g. PURCHASE on a signup-only SaaS whose only purchase action is Google's never-firing
 * default). Prevents new campaigns inheriting the empty goal and hitting "targeted goal is
 * missing a primary conversion action". Dry-run by default; --apply to mutate.
 */
class DisableAccountConversionGoal extends Command
{
    protected $signature = 'conversions:disable-account-goal {customer : Laravel customer id}
        {--category=PURCHASE : Conversion-goal category to make non-biddable}
        {--apply : Actually mutate (otherwise dry-run)}';

    protected $description = 'Set an account-level conversion goal (default PURCHASE) to non-biddable.';

    public function handle(): int
    {
        $customer = Customer::find($this->argument('customer'));
        if (!$customer || !$customer->google_ads_customer_id) {
            $this->error('Customer not found or has no Google Ads account.');
            return 1;
        }

        $categoryName = strtoupper($this->option('category'));
        $categoryVal  = ConversionActionCategory::value($categoryName);
        $customerId   = $customer->cleanGoogleCustomerId();

        $svc = new class($customer) extends BaseGoogleAdsService {
            public function goals(string $cid): array
            {
                $this->ensureClient();
                $rows = [];
                $q = "SELECT customer_conversion_goal.resource_name, customer_conversion_goal.category, "
                    . "customer_conversion_goal.biddable FROM customer_conversion_goal";
                foreach ($this->searchQuery($cid, $q)->iterateAllElements() as $row) {
                    $g = $row->getCustomerConversionGoal();
                    $rows[] = ['resource' => $g->getResourceName(), 'category' => $g->getCategory(), 'biddable' => $g->getBiddable()];
                }
                return $rows;
            }

            public function mutate(string $cid, array $ops): int
            {
                return count($this->client->getCustomerConversionGoalServiceClient()
                    ->mutateCustomerConversionGoals(new MutateCustomerConversionGoalsRequest([
                        'customer_id' => $cid,
                        'operations'  => $ops,
                    ]))->getResults());
            }
        };

        $target = null;
        foreach ($svc->goals($customerId) as $g) {
            $name = ConversionActionCategory::name($g['category']);
            $this->line(sprintf('%s %s (biddable=%s)', $g['category'] === $categoryVal ? '~' : ' ', $name, $g['biddable'] ? 'yes' : 'no'));
            if ($g['category'] === $categoryVal) {
                $target = $g;
            }
        }

        if (!$target) {
            $this->info("Account does not have a {$categoryName} goal — nothing to do.");
            return 0;
        }
        if (!$target['biddable']) {
            $this->info("{$categoryName} account goal is already non-biddable.");
            return 0;
        }

        if (!$this->option('apply')) {
            $this->warn("Would set the account {$categoryName} goal to non-biddable. Re-run with --apply.");
            return 0;
        }

        $goal = new CustomerConversionGoal(['resource_name' => $target['resource'], 'biddable' => false]);
        $op = new CustomerConversionGoalOperation();
        $op->setUpdate($goal);
        $op->setUpdateMask(new FieldMask(['paths' => ['biddable']]));

        $n = $svc->mutate($customerId, [$op]);
        $this->info("Set account {$categoryName} goal to non-biddable ({$n} change).");
        return 0;
    }
}
