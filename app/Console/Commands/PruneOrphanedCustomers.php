<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class PruneOrphanedCustomers extends Command
{
    protected $signature = 'customers:prune-orphaned
                            {--apply : Actually delete. Without this the command only reports.}';

    protected $description = 'Report (or remove) customers left with no owner by a deleted user';

    public function handle(): int
    {
        $orphans = Customer::query()
            ->whereDoesntHave('users')
            ->withCount('campaigns')
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('No orphaned customers.');

            return Command::SUCCESS;
        }

        $this->warn("{$orphans->count()} customer(s) have no owner:");

        $disposable = [];
        $needsAHuman = [];

        foreach ($orphans as $customer) {
            $deployed = $customer->campaigns()
                ->where(function (Builder $q) {
                    $q->whereNotNull('google_ads_campaign_id')
                        ->orWhereNotNull('facebook_ads_campaign_id')
                        ->orWhereNotNull('microsoft_ads_campaign_id')
                        ->orWhereNotNull('linkedin_campaign_id');
                })
                ->count();

            $balance = (float) ($customer->adSpendCredit()->value('current_balance') ?? 0);

            $row = [
                $customer->id,
                $customer->name,
                $customer->campaigns_count,
                $deployed,
                number_format($balance, 2),
            ];

            // Anything live or holding money is a human decision, not a prune.
            if ($deployed > 0 || $balance > 0) {
                $needsAHuman[] = $row;
            } else {
                $disposable[] = $row;
            }
        }

        $headers = ['ID', 'Name', 'Campaigns', 'Deployed', 'Credit'];

        if ($needsAHuman !== []) {
            $this->newLine();
            $this->error('Live ads or unspent credit — NOT removed. Reassign or wind these down by hand:');
            $this->table($headers, $needsAHuman);
        }

        if ($disposable === []) {
            return Command::SUCCESS;
        }

        $this->newLine();
        $this->line('Nothing deployed, no credit — safe to retire (soft-delete):');
        $this->table($headers, $disposable);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->comment('Dry run. Re-run with --apply to delete the '.count($disposable).' safe row(s).');

            return Command::SUCCESS;
        }

        $ids = array_column($disposable, 0);
        Customer::whereIn('id', $ids)->each(fn (Customer $c) => $c->delete());

        $this->info('Retired '.count($ids).' orphaned customer(s): '.implode(', ', $ids));

        return Command::SUCCESS;
    }
}
