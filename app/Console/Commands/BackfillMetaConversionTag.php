<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\FacebookAds\PixelService;
use App\Services\GTM\GTMContainerService;
use Illuminate\Console\Command;

/**
 * Add the Meta conversion tag to containers provisioned before it existed.
 *
 * ConversionSetupService now creates two Meta tags: the base pixel, and a
 * conversion event on form submit. Customers set up before that only have the
 * base tag, and nothing will revisit them — ensureConversionTracking returns
 * early once conversion_action_id is set, by design, so that re-saving a
 * customer does not re-provision their container.
 *
 * Until this runs, those campaigns report page views to Meta and no conversions,
 * which is the state that made the tag worth adding in the first place.
 *
 * addFacebookConversionTag is idempotent — it patches the trigger onto an
 * existing tag of the same name rather than creating a duplicate — so this is
 * safe to run more than once.
 */
class BackfillMetaConversionTag extends Command
{
    protected $signature = 'meta:backfill-conversion-tag
                            {--customer= : Restrict to one customer id}
                            {--dry-run : List who would be updated without calling GTM}';

    protected $description = 'Add the Meta conversion tag to GTM containers provisioned before it existed';

    public function handle(GTMContainerService $gtm): int
    {
        // All three, matching what addFacebookConversionTag requires. Filtering
        // on the container id alone reported a half-provisioned customer as a
        // failure rather than as one that was never set up.
        $customers = Customer::query()
            ->whereNotNull('gtm_container_id')
            ->whereNotNull('gtm_account_id')
            ->whereNotNull('gtm_workspace_id')
            ->when($this->option('customer'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        if ($customers->isEmpty()) {
            $this->info('No customers with a provisioned GTM container.');

            return self::SUCCESS;
        }

        $this->line("Found {$customers->count()} customer(s) with a container.");

        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($customers as $customer) {
            // Guarded per customer: one container without a pixel, or one GTM
            // error, must not stop the rest of the backfill.
            try {
                $pixelId = (new PixelService($customer))->resolvePixelId();

                if (! $pixelId) {
                    $this->line("  skip  #{$customer->id} {$customer->name} — no Meta pixel");
                    $skipped++;

                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("  would #{$customer->id} {$customer->name} — pixel {$pixelId}");
                    $updated++;

                    continue;
                }

                $result = $gtm->addFacebookConversionTag($customer, $pixelId);

                if ($result['success']) {
                    $note = ($result['existing'] ?? false) ? 'already present' : 'created';
                    $this->info("  ok    #{$customer->id} {$customer->name} — {$note}");
                    $updated++;
                } else {
                    $this->error("  fail  #{$customer->id} {$customer->name} — ".($result['error'] ?? 'unknown'));
                    $failed++;
                }
            } catch (\Throwable $e) {
                report($e);
                $this->error("  fail  #{$customer->id} {$customer->name} — ".$e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->line("updated {$updated}, skipped {$skipped}, failed {$failed}");

        if (! $this->option('dry-run') && $updated > 0) {
            $this->warn('GTM changes sit in the workspace until the container is published.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
