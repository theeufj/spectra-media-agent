<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\AddKeyword;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use App\Services\GoogleAds\SearchServices\CreateSearchAdGroup;
use App\Services\GoogleAds\SearchServices\CreateSearchCampaign;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Illuminate\Console\Command;

/**
 * Proves the full deployment chain works, against a real account.
 *
 * validate_only cannot cover this: it creates nothing, so a later operation has
 * no ID to reference, and campaign → ad group → keyword is exactly that shape.
 * The only way to prove the chain is to build one.
 *
 * Four safety properties, in the order they matter:
 *
 *  1. The campaign is created PAUSED, explicitly, never relying on the account's
 *     default. Production runs in live mode, where the default is ENABLED — a
 *     verification campaign that inherited that would start serving real ads.
 *  2. The budget is the minimum the API accepts, so even a total failure of the
 *     other three bounds the exposure to a rounding error.
 *  3. Cleanup runs in a finally block, so it happens on success, on failure, and
 *     on exception alike.
 *  4. Everything is named with a fixed prefix, and cleanup refuses to touch
 *     anything not carrying it. A bug in this command cannot remove a real
 *     campaign.
 *
 * If a run is killed hard enough to skip the finally, --sweep removes anything
 * left behind.
 *
 * Usage:
 *   php artisan googleads:verify-deployment 8
 *   php artisan googleads:verify-deployment 8 --keep    (leave it for inspection)
 *   php artisan googleads:verify-deployment 8 --sweep   (remove orphans only)
 */
class VerifyDeploymentEndToEnd extends Command
{
    protected $signature = 'googleads:verify-deployment
                              {customer : Customer ID}
                              {--keep : Do not clean up — leave the objects for inspection}
                              {--sweep : Only remove orphans from previous runs}';

    protected $description = 'Build and tear down a real paused campaign to prove the deployment chain works end to end.';

    /**
     * Every object this command creates carries this prefix, and cleanup will
     * not touch anything without it.
     */
    private const PREFIX = 'ZZ_SPECTRA_VERIFY';

    /**
     * Daily budget in ACCOUNT CURRENCY UNITS, not micros.
     *
     * CreateSearchCampaign multiplies this by 1,000,000 itself. Passing micros
     * here would ask Google for a budget of A$1,000,000/day — the campaign is
     * created paused so it could not have spent, but the value would have been
     * wrong by six orders of magnitude.
     */
    private const DAILY_BUDGET = 1.0;

    private ?string $campaignResource = null;

    public function handle(): int
    {
        $customer = Customer::find($this->argument('customer'));

        if (! $customer?->google_ads_customer_id) {
            $this->error('Customer not found, or has no Google Ads account.');

            return self::FAILURE;
        }

        $customerId = $customer->cleanGoogleCustomerId();

        if ($this->option('sweep')) {
            return $this->sweep($customer, $customerId);
        }

        $this->info("Verifying the deployment chain against account {$customerId}.");
        $this->line('<comment>The campaign is created PAUSED and is removed again at the end. It cannot serve or spend.</comment>');
        $this->newLine();

        $passed = 0;
        $failed = 0;

        try {
            // ── 1. Campaign (+ budget) ──────────────────────────────────────
            $name = self::PREFIX.'_'.now()->format('Ymd_His');

            $this->campaignResource = (new CreateSearchCampaign($customer))($customerId, [
                'businessName' => $name,
                'budget' => self::DAILY_BUDGET,
                'startDate' => now()->addDay()->format('Ymd'),
                'endDate' => now()->addDays(2)->format('Ymd'),
                // Explicit, never inherited. Production defaults to ENABLED.
                'status' => 'PAUSED',
            ]);

            if (! $this->campaignResource) {
                $this->line('  <error>failed</error>    create campaign');

                return self::FAILURE;
            }

            $this->line("  <info>ok</info>        create campaign — {$this->campaignResource}");
            $passed++;

            // Confirm it really is paused before building anything on top of it.
            if (! $this->confirmPaused($customer, $customerId)) {
                $this->error('  Campaign is NOT paused. Removing it immediately and stopping.');

                return self::FAILURE;
            }
            $this->line('  <info>ok</info>        confirmed PAUSED before proceeding');
            $passed++;

            // ── 2. Ad group, referencing the campaign just created ──────────
            $adGroupResource = (new CreateSearchAdGroup($customer))(
                $customerId,
                $this->campaignResource,
                $name.'_adgroup'
            );

            if (! $adGroupResource) {
                $this->line('  <error>failed</error>    create ad group');
                $failed++;
            } else {
                $this->line("  <info>ok</info>        create ad group — {$adGroupResource}");
                $passed++;

                // ── 3. Keyword, referencing the ad group just created ───────
                $keyword = (new AddKeyword($customer))(
                    $customerId,
                    $adGroupResource,
                    'spectra deployment verification',
                    KeywordMatchType::EXACT
                );

                if ($keyword) {
                    $this->line("  <info>ok</info>        add keyword — {$keyword}");
                    $passed++;
                } else {
                    $this->line('  <error>failed</error>    add keyword');
                    $failed++;
                }
            }
        } catch (\Throwable $e) {
            $this->line('  <error>failed</error>    '.$this->firstLine($e->getMessage()));
            $failed++;
        } finally {
            // Runs on success, failure and exception alike.
            if ($this->option('keep')) {
                $this->newLine();
                $this->warn('--keep given: objects left in the account. Run with --sweep to remove them.');
            } else {
                $this->newLine();
                $this->cleanup($customer, $customerId);
            }
        }

        $this->newLine();
        $this->info("{$passed} step(s) passed, {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Verify with Google — not with our own record — that the campaign is paused.
     */
    private function confirmPaused(Customer $customer, string $customerId): bool
    {
        foreach ($this->findOwnCampaigns($customer, $customerId) as $row) {
            if ($row['resource_name'] === $this->campaignResource) {
                return $row['status'] === 3; // 3 = PAUSED
            }
        }

        return false;
    }

    private function cleanup(Customer $customer, string $customerId): void
    {
        if (! $this->campaignResource) {
            return;
        }

        // Removing the campaign removes its ad groups, keywords and ads with it.
        try {
            (new UpdateCampaignStatus($customer))->execute($customerId, $this->campaignResource, 'REMOVED');
            $this->line("  <info>cleaned</info>   removed {$this->campaignResource}");
        } catch (\Throwable $e) {
            $this->error('  CLEANUP FAILED — run with --sweep to remove it: '.$this->firstLine($e->getMessage()));
        }
    }

    /**
     * Remove anything left behind by an earlier run.
     *
     * Only campaigns whose name carries the prefix are eligible, so this cannot
     * remove a real campaign even if called by mistake.
     */
    private function sweep(Customer $customer, string $customerId): int
    {
        $this->info("Sweeping orphaned verification campaigns in {$customerId}...");

        $found = 0;

        foreach ($this->findOwnCampaigns($customer, $customerId) as $row) {
            if (! str_starts_with($row['name'], self::PREFIX)) {
                continue; // belt and braces — the query already filters
            }

            try {
                (new UpdateCampaignStatus($customer))->execute($customerId, $row['resource_name'], 'REMOVED');
                $this->line("  <info>removed</info>   {$row['name']}");
                $found++;
            } catch (\Throwable $e) {
                $this->error("  failed to remove {$row['name']}: ".$this->firstLine($e->getMessage()));
            }
        }

        $this->info($found === 0 ? 'Nothing to sweep.' : "Removed {$found} orphan(s).");

        return self::SUCCESS;
    }

    /**
     * Campaigns created by this command, and only those.
     *
     * @return list<array{resource_name: string, name: string, status: int}>
     */
    private function findOwnCampaigns(Customer $customer, string $customerId): array
    {
        $service = new class($customer) extends \App\Services\GoogleAds\BaseGoogleAdsService
        {
            public function query(string $customerId, string $gaql): \Google\ApiCore\PagedListResponse
            {
                $this->ensureClient();

                return $this->searchQuery($customerId, $gaql);
            }
        };

        $rows = [];

        $response = $service->query($customerId,
            "SELECT campaign.resource_name, campaign.name, campaign.status
             FROM campaign
             WHERE campaign.name LIKE '".self::PREFIX."%'
             AND campaign.status != 'REMOVED'"
        );

        foreach ($response->iterateAllElements() as $row) {
            $campaign = $row->getCampaign();
            $rows[] = [
                'resource_name' => $campaign->getResourceName(),
                'name' => $campaign->getName(),
                'status' => $campaign->getStatus(),
            ];
        }

        return $rows;
    }

    private function firstLine(string $message): string
    {
        if (preg_match('/"message":\s*"([^"]+)"/', $message, $m)) {
            return $m[1];
        }

        return mb_substr(strtok($message, "\n") ?: $message, 0, 200);
    }
}
