<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\GTM\GTMContainerService;
use Illuminate\Console\Command;

/**
 * Publish a customer's GTM container.
 *
 * Everything the platform writes — conversion tags, the Meta pixel, triggers —
 * lands in the container's workspace and does nothing at all until a version is
 * created and published. A container full of correct tags that has never been
 * published tracks exactly as much as an empty one, which makes this the step
 * most easily forgotten and the hardest to notice missing.
 */
class PublishGtmContainer extends Command
{
    protected $signature = 'gtm:publish
                            {customer : Customer id}
                            {--notes= : Version notes shown in the GTM UI}
                            {--dry-run : Show what would be published without publishing}';

    protected $description = "Create and publish a version of a customer's GTM container";

    public function handle(GTMContainerService $gtm): int
    {
        $customer = Customer::find($this->argument('customer'));

        if (! $customer) {
            $this->error('No customer with id '.$this->argument('customer'));

            return self::FAILURE;
        }

        if (! $customer->gtm_container_id || ! $customer->gtm_account_id || ! $customer->gtm_workspace_id) {
            $this->error($customer->name.' has no fully provisioned GTM container.');

            return self::FAILURE;
        }

        $this->line('Customer  : '.$customer->name.' (#'.$customer->id.')');
        $this->line('Container : '.$customer->gtm_container_id);
        $this->line('Workspace : '.$customer->gtm_workspace_id);

        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing published.');

            return self::SUCCESS;
        }

        $notes = $this->option('notes') ?: 'Published via gtm:publish';

        $result = $gtm->publishContainer($customer, $notes);

        if (! $result['success']) {
            $this->error('Publish failed: '.($result['error'] ?? 'unknown'));

            return self::FAILURE;
        }

        $this->info('Published version '.($result['version_id'] ?? '?').'.');
        $this->line('Tags in this container are now live on every page carrying the snippet.');

        return self::SUCCESS;
    }
}
