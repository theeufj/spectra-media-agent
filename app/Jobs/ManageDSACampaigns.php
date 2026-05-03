<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\Agents\DSAManagementAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Weekly job that creates or updates DSA campaigns for customers
 * who have crawled website content in their knowledge base.
 *
 * Schedule: Wednesdays at 04:00.
 */
class ManageDSACampaigns implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 2;
    public $timeout = 600;

    public function handle(DSAManagementAgent $agent): void
    {
        $customers = Customer::whereNotNull('google_ads_customer_id')
            ->whereHas('campaigns', fn ($q) => $q->where('status', 'active'))
            ->whereHas('users', fn ($q) => $q->whereHas('knowledgeBases', fn ($kq) => $kq->whereNotNull('url')))
            ->get();

        $summary = ['processed' => 0, 'created' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($customers as $customer) {
            try {
                $result = $agent->manage($customer);

                $summary['processed']++;

                if ($result['skipped'] ?? false) {
                    $summary['skipped']++;
                } elseif ($result['success'] ?? false) {
                    $summary['created'] += count($result['created'] ?? []);
                }
            } catch (\Exception $e) {
                $summary['errors']++;
                Log::error("ManageDSACampaigns: Error for customer {$customer->id}: " . $e->getMessage());
            }

            // Promote high-performing DSA search terms to regular campaigns (independent of setup result)
            try {
                $agent->promoteHighPerformingTerms($customer);
            } catch (\Exception $e) {
                Log::error("ManageDSACampaigns: Promotion error for customer {$customer->id}: " . $e->getMessage());
            }
        }

        Log::info('ManageDSACampaigns: Complete', $summary);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ManageDSACampaigns failed: ' . $exception->getMessage());
    }
}
