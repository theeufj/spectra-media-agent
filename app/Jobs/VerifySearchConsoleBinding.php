<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\SEO\SearchConsoleService;
use App\Support\WorkStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class VerifySearchConsoleBinding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public int $customerId, public string $workRunId) {}

    public function handle(SearchConsoleService $service): void
    {
        WorkStatus::update($this->customerId, 'search-console-verification', $this->workRunId, 'running', 'Checking your assigned tracking container and website ownership.');
        $customer = Customer::find($this->customerId);
        if (! $customer) {
            WorkStatus::update($this->customerId, 'search-console-verification', $this->workRunId, 'failed', 'The customer no longer exists.');

            return;
        }
        $result = $service->verifyViaTagManager($customer);
        WorkStatus::update($this->customerId, 'search-console-verification', $this->workRunId, $result['success'] ? 'completed' : 'failed', $result['success']
            ? 'Website ownership verified. You can now measure organic queries and check Google indexing.'
            : $result['error']);
    }

    public function failed(\Throwable $exception): void
    {
        WorkStatus::update($this->customerId, 'search-console-verification', $this->workRunId, 'failed', 'Verification could not finish. Please retry.');
    }
}
