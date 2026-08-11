<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\AddNegativeKeyword;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBudget;
use App\Services\GoogleAds\CreateCampaignBudget;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Illuminate\Console\Command;

/**
 * Validates real mutations against a real Google Ads account without applying
 * any of them.
 *
 * Every mutate is sent with validate_only, so Google checks required fields,
 * policy, budget shape and resource references, then discards the request. This
 * catches the class of bug that only appears when the API sees the payload —
 * malformed budgets, invalid match types, bad resource names — without a test
 * account, a second credential set, or a cent of spend.
 *
 * Safe to run against production. Nothing is created, updated or removed.
 *
 * Usage:
 *   php artisan googleads:dry-run {customer}
 *   php artisan googleads:dry-run 8 --campaign=customers/123/campaigns/456
 */
class DryRunCampaignMutations extends Command
{
    protected $signature = 'googleads:dry-run
                              {customer : Customer ID}
                              {--campaign= : Campaign resource name for update checks}';

    protected $description = 'Validate Google Ads mutations against the real API without applying them (validate_only).';

    public function handle(): int
    {
        $customer = Customer::find($this->argument('customer'));

        if (! $customer?->google_ads_customer_id) {
            $this->error('Customer not found, or has no Google Ads account.');

            return self::FAILURE;
        }

        $customerId = $customer->cleanGoogleCustomerId();

        $this->info("Dry-running mutations against account {$customerId}.");
        $this->line('<comment>validate_only is set on every request — nothing will be created or changed.</comment>');
        $this->newLine();

        $passed = 0;
        $failed = 0;

        $checks = [
            // Micros, not currency units — 10_000_000 is A$10/day.
            'create campaign budget' => fn () => (new CreateCampaignBudget($customer))
                ->dryRun()
                ->__invoke($customerId, 'Spectra dry-run budget '.uniqid(), 10_000_000),
        ];

        if ($campaign = $this->option('campaign')) {
            $checks['update campaign budget'] = fn () => (new UpdateCampaignBudget($customer))
                ->dryRun()
                ->__invoke($customerId, $campaign, 25_000_000);

            $checks['add negative keyword'] = fn () => (new AddNegativeKeyword($customer))
                ->dryRun()
                ->__invoke($customerId, $campaign, 'spectra dry run term', KeywordMatchType::PHRASE);
        } else {
            $this->line('  <comment>skipped</comment>  update/negative checks — pass --campaign=<resource name> to include them');
        }

        foreach ($checks as $label => $check) {
            try {
                $check();
                $this->line("  <info>ok</info>        {$label}");
                $passed++;
            } catch (\Throwable $e) {
                // The message is the point of the exercise: Google explains
                // exactly which field it rejected and why.
                $this->line("  <error>failed</error>    {$label}");
                $this->line('            '.$this->firstLine($e->getMessage()));
                $failed++;
            }
        }

        $this->newLine();
        $this->info("{$passed} passed, {$failed} failed. Nothing was applied.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function firstLine(string $message): string
    {
        // Google Ads errors arrive as a large JSON blob; the human-readable
        // reason is what matters here.
        if (preg_match('/"message":\s*"([^"]+)"/', $message, $m)) {
            return $m[1];
        }

        return mb_substr(strtok($message, "\n") ?: $message, 0, 200);
    }
}
