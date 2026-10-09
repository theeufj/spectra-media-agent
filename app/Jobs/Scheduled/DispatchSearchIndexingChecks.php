<?php

namespace App\Jobs\Scheduled;

use App\Jobs\CheckSearchIndexing;
use App\Models\Customer;
use App\Support\WorkStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchSearchIndexingChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function handle(): void
    {
        Customer::whereNotNull('website')->where('website', '!=', '')->each(function ($customer) {
            try {
                $runId = WorkStatus::start($customer->id, 'indexing');
                if ($runId) {
                    CheckSearchIndexing::dispatch($customer->id, $runId);
                }
            } catch (\Throwable $e) {
                report($e);
                \Illuminate\Support\Facades\Log::error('Search indexing dispatch failed', ['customer_id' => $customer->id, 'error' => $e->getMessage()]);
            }
        });
    }
}
