<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Customer;
use App\Notifications\CriticalAgentAlert;
use App\Services\GTM\GTMContainerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Checks whether customers have actually installed their GTM snippet.
 *
 * Provisioning a container is the half we control. Installing the snippet on the
 * site is the half the customer has to do, and until they do it no conversion
 * is ever recorded — the tags exist but nothing fires them.
 *
 * GTMContainerService::verifySnippetInstalled() existed but was reachable only
 * from a controller, i.e. only if someone opened the page and clicked. Nothing
 * checked periodically, so a customer who never installed the snippet looked
 * identical to one who had: a provisioned container and silence. Of nine
 * customers, exactly one has ever been verified.
 *
 * This is the same shape as the conversion-upload and remediation faults found
 * elsewhere: the system could tell, and never asked.
 */
class VerifyGtmInstallation implements ShouldQueue
{
    use \App\Jobs\Concerns\RecordsAgentRun, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 600;

    /**
     * Re-check an already-verified customer no more often than this. A site can
     * lose its snippet in a redeploy, so verification is not permanent — but it
     * does not need checking hourly either.
     */
    private const RECHECK_AFTER_DAYS = 7;

    /**
     * How long a customer may sit with a container they have not installed
     * before someone is told. Long enough not to nag during setup, short enough
     * that they are not left silently untracked.
     */
    private const CHASE_AFTER_DAYS = 3;

    public function handle(GTMContainerService $gtm): void
    {
        $runStart = $this->startRun();

        $customers = Customer::whereNotNull('gtm_container_id')
            ->whereNotNull('website')
            ->where('is_sandbox', false)
            ->where(function ($q) {
                $q->whereNull('gtm_last_verified')
                    ->orWhere('gtm_last_verified', '<', now()->subDays(self::RECHECK_AFTER_DAYS));
            })
            ->get();

        $verified = 0;
        $missing = 0;
        $errors = 0;

        foreach ($customers as $customer) {
            try {
                $result = $gtm->verifySnippetInstalled($customer);

                // A failed check is not evidence the snippet is missing — the
                // site may be down, slow, or blocking us. Recording that as
                // "not installed" would chase customers who did nothing wrong
                // and, worse, flip a previously-verified customer to false.
                if (! ($result['success'] ?? false)) {
                    $errors++;
                    Log::warning('VerifyGtmInstallation: could not check site, leaving state unchanged', [
                        'customer_id' => $customer->id,
                        'error' => $result['error'] ?? 'unknown',
                    ]);

                    continue;
                }

                $installed = (bool) $result['installed'];

                $customer->update([
                    'gtm_installed' => $installed,
                    'gtm_last_verified' => now(),
                ]);

                if ($installed) {
                    $verified++;

                    continue;
                }

                $missing++;
                $this->chaseIfOverdue($customer);
            } catch (\Throwable $e) {
                // Surface in the admin dashboard; keep the batch running.
                report($e);
                $errors++;
                Log::error("VerifyGtmInstallation: failed for customer {$customer->id}: ".$e->getMessage());
            }
        }

        Log::info('VerifyGtmInstallation: complete', [
            'checked' => $customers->count(),
            'installed' => $verified,
            'missing' => $missing,
        ]);

        $this->finishRun($runStart, actions: $verified + $missing, errors: $errors, scope: $customers->count().' customers');
    }

    /**
     * Tell someone when a container has sat uninstalled long enough to matter.
     *
     * Recorded either way, so "provisioned but never installed" is visible as a
     * state rather than inferred from an absence of conversions.
     */
    private function chaseIfOverdue(Customer $customer): void
    {
        $provisionedAt = $customer->gtm_config['provisioned_at'] ?? null;

        AgentActivity::record(
            'conversion_tracking',
            'gtm_snippet_missing',
            'GTM container '.$customer->gtm_container_id.' is not installed on '.$customer->website,
            $customer->id,
            null,
            ['container_id' => $customer->gtm_container_id, 'website' => $customer->website]
        );

        if (! $provisionedAt || now()->diffInDays($provisionedAt) < self::CHASE_AFTER_DAYS) {
            return;
        }

        $cacheKey = "gtm_install_chased:{$customer->id}";
        if (cache()->has($cacheKey)) {
            return;
        }
        cache()->put($cacheKey, true, now()->addDays(self::CHASE_AFTER_DAYS));

        CriticalAgentAlert::deliver(
            'gtm_not_installed',
            'Conversion tracking is not live for '.$customer->name,
            "Container {$customer->gtm_container_id} was created for {$customer->website} but the snippet is not on the site, so no conversion is being recorded. "
            .'Campaigns for this customer will bid without conversion data until it is installed.',
            ['customer_id' => $customer->id, 'container_id' => $customer->gtm_container_id],
            CriticalAgentAlert::RECIPIENTS_ADMINS,
            $customer
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('VerifyGtmInstallation failed: '.$exception->getMessage());
        $this->recordRunFailure($exception);
    }
}
