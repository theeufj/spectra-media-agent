<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\ConversionAction;
use Google\Ads\GoogleAds\V22\Services\ConversionActionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateConversionActionsRequest;
use Google\Protobuf\FieldMask;
use Illuminate\Console\Command;

/**
 * Keep only the chosen conversion actions primary (bid targets) and demote the rest to
 * secondary. Too many primaries dilute Smart Bidding toward low-value micro-conversions.
 * Secondary actions are still tracked — just not optimised toward.
 *
 * Dry-run by default; pass --apply to mutate.
 */
class SetConversionPrimaries extends Command
{
    protected $signature = 'conversions:set-primaries {customer : Laravel customer id}
        {--keep=signup,sign-up,try now : Comma-separated name fragments to keep primary}
        {--apply : Actually mutate (otherwise dry-run)}';

    protected $description = 'Keep only chosen conversion actions primary; demote the rest to secondary.';

    public function handle(): int
    {
        $customer = Customer::find($this->argument('customer'));
        if (!$customer || !$customer->google_ads_customer_id) {
            $this->error('Customer not found or has no Google Ads account.');
            return 1;
        }

        $keep = array_filter(array_map('trim', explode(',', strtolower($this->option('keep')))));
        $apply = (bool) $this->option('apply');
        $customerId = $customer->cleanGoogleCustomerId();

        $svc = new class($customer) extends BaseGoogleAdsService {
            public function actions(string $cid): array
            {
                $this->ensureClient();
                $rows = [];
                $q = "SELECT conversion_action.resource_name, conversion_action.name, conversion_action.primary_for_goal "
                    . "FROM conversion_action WHERE conversion_action.status = 'ENABLED'";
                foreach ($this->searchQuery($cid, $q)->iterateAllElements() as $row) {
                    $ca = $row->getConversionAction();
                    $rows[] = ['resource' => $ca->getResourceName(), 'name' => $ca->getName(), 'primary' => $ca->getPrimaryForGoal()];
                }
                return $rows;
            }

            public function mutate(string $cid, array $ops): int
            {
                return count($this->client->getConversionActionServiceClient()
                    ->mutateConversionActions(new MutateConversionActionsRequest([
                        'customer_id' => $cid,
                        'operations'  => $ops,
                    ]))->getResults());
            }
        };

        $actions = $svc->actions($customerId);
        $ops = [];

        foreach ($actions as $a) {
            $shouldBePrimary = false;
            foreach ($keep as $frag) {
                if ($frag !== '' && str_contains(strtolower($a['name']), $frag)) {
                    $shouldBePrimary = true;
                    break;
                }
            }

            $verb = $shouldBePrimary ? 'PRIMARY' : 'secondary';
            $changing = $a['primary'] !== $shouldBePrimary;
            $this->line(sprintf('%s %s -> %s', $changing ? '~' : ' ', $a['name'], $verb));

            if ($changing) {
                $ca = new ConversionAction(['resource_name' => $a['resource'], 'primary_for_goal' => $shouldBePrimary]);
                $op = new ConversionActionOperation();
                $op->setUpdate($ca);
                $op->setUpdateMask(new FieldMask(['paths' => ['primary_for_goal']]));
                $ops[] = $op;
            }
        }

        if (empty($ops)) {
            $this->info('Nothing to change.');
            return 0;
        }

        if (!$apply) {
            $this->warn(count($ops) . ' change(s) pending. Re-run with --apply to mutate.');
            return 0;
        }

        $n = $svc->mutate($customerId, $ops);
        $this->info("Applied {$n} conversion-action change(s).");
        return 0;
    }
}
