<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\SEO\IndexingHealthService;
use App\Support\WorkStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckSearchIndexing implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1200;

    public int $uniqueFor = 1800;

    public function __construct(public int $customerId, public ?string $workRunId = null) {}

    public function uniqueId(): string
    {
        return (string) $this->customerId;
    }

    public function handle(IndexingHealthService $service): void
    {
        WorkStatus::update($this->customerId, 'indexing', $this->workRunId, 'running', 'Checking Google’s indexed versions of your sitemap pages.');
        $customer = Customer::find($this->customerId);
        if ($customer && $customer->website) {
            $audit = $service->auditSite($customer);
            $checked = $audit->indexing_analysis['checked_pages'];
            WorkStatus::update($this->customerId, 'indexing', $this->workRunId, $checked > 0 ? 'completed' : 'failed',
                $checked.' pages inspected; '.count($audit->issues).' findings. Open Google indexing health for the evidence and next steps.');
        } else {
            WorkStatus::update($this->customerId, 'indexing', $this->workRunId, 'failed', 'Set a website URL before checking indexing.');
        }
    }

    public function failed(\Throwable $exception): void
    {
        WorkStatus::update($this->customerId, 'indexing', $this->workRunId, 'failed', 'The indexing check could not finish. Retry the check.');
    }
}
