<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Customer;
use App\Models\MccAccount;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Notices when a customer accepts (or refuses) our request to manage their
 * Google Ads account.
 *
 * Google sends no webhook for this, so the only way to know is to ask. Without
 * it, an accepted invitation would sit unrecognised and the customer would stay
 * un-onboarded despite having done exactly what we asked — the same shape of
 * silence as a container provisioned but never verified.
 */
class CheckPendingGoogleAdsLinks implements ShouldQueue
{
    use \App\Jobs\Concerns\RecordsAgentRun, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 300;

    /** CustomerClientLinkStatus enum values. */
    private const STATUS_ACTIVE = 2;

    private const STATUS_INACTIVE = 3;

    private const STATUS_PENDING = 4;

    private const STATUS_REFUSED = 5;

    private const STATUS_CANCELLED = 6;

    public function handle(): void
    {
        $runStart = $this->startRun();

        $pending = Customer::where('google_ads_link_status', 'pending')
            ->whereNotNull('google_ads_customer_id')
            ->get();

        if ($pending->isEmpty()) {
            $this->finishRun($runStart, actions: 0, errors: 0, scope: 'no pending links');

            return;
        }

        $mcc = MccAccount::getActive();

        if (! $mcc) {
            Log::error('CheckPendingGoogleAdsLinks: no active MCC account');
            $this->finishRun($runStart, actions: 0, errors: 1, scope: 'no MCC');

            return;
        }

        $managerId = preg_replace('/[^0-9]/', '', $mcc->google_customer_id);
        $statuses = $this->linkStatuses($pending->first(), $managerId);

        $resolved = 0;

        foreach ($pending as $customer) {
            $clientId = preg_replace('/[^0-9]/', '', (string) $customer->google_ads_customer_id);
            $status = $statuses[$clientId] ?? null;

            // Absent means the link no longer exists on Google's side — but a
            // failed query returns an empty map too, so absence is treated as
            // "no news" rather than as a refusal.
            if ($status === null || $status === self::STATUS_PENDING) {
                continue;
            }

            if ($status === self::STATUS_ACTIVE) {
                $customer->update([
                    'google_ads_link_status' => 'active',
                    'google_ads_link_confirmed_at' => now(),
                    'google_ads_manager_customer_id' => $managerId,
                ]);

                AgentActivity::record(
                    'onboarding',
                    'google_ads_account_linked',
                    'Google Ads account '.$clientId.' is now managed for "'.$customer->name.'"',
                    $customer->id,
                    null,
                    ['client_account' => $clientId, 'manager_account' => $managerId]
                );

                Log::info('CheckPendingGoogleAdsLinks: link accepted', [
                    'customer_id' => $customer->id,
                    'client_account' => $clientId,
                ]);

                $resolved++;

                continue;
            }

            $customer->update([
                'google_ads_link_status' => match ($status) {
                    self::STATUS_REFUSED => 'refused',
                    self::STATUS_CANCELLED => 'cancelled',
                    self::STATUS_INACTIVE => 'inactive',
                    default => 'unknown',
                },
            ]);

            AgentActivity::record(
                'onboarding',
                'google_ads_link_not_accepted',
                'Link request for "'.$customer->name.'" was not accepted (status '.$status.')',
                $customer->id,
                null,
                ['client_account' => $clientId, 'status' => $status]
            );

            $resolved++;
        }

        $this->finishRun($runStart, actions: $resolved, errors: 0, scope: $pending->count().' pending links');
    }

    /**
     * Current status of every client link on the manager account.
     *
     * Fetched in one query rather than per customer: the manager holds a single
     * list, and asking once per customer would be the same call repeated.
     *
     * @return array<string, int> client customer id => status
     */
    private function linkStatuses(Customer $anyCustomer, string $managerId): array
    {
        $service = new class($anyCustomer) extends BaseGoogleAdsService
        {
            public function query(string $customerId, string $gaql): \Google\ApiCore\PagedListResponse
            {
                $this->ensureClient();

                return $this->searchQuery($customerId, $gaql);
            }
        };

        try {
            $rows = $service->query($managerId,
                'SELECT customer_client_link.client_customer, customer_client_link.status
                 FROM customer_client_link'
            );

            $map = [];

            foreach ($rows->iterateAllElements() as $row) {
                $link = $row->getCustomerClientLink();
                $clientId = str_replace('customers/', '', $link->getClientCustomer());
                $map[$clientId] = $link->getStatus();
            }

            return $map;
        } catch (\Throwable $e) {
            report($e);
            Log::error('CheckPendingGoogleAdsLinks: could not read link statuses: '.$e->getMessage());

            return [];
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CheckPendingGoogleAdsLinks failed: '.$exception->getMessage());
        $this->recordRunFailure($exception);
    }
}
