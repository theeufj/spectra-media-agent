<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\SEO\SeoAuditService;
use App\Support\WorkStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunSeoAudit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public int $customerId,
        public string $url,
        public ?string $workRunId = null,
    ) {}

    public function handle(): void
    {
        WorkStatus::update($this->customerId, 'seo-audit', $this->workRunId, 'running', 'Working on your request.');
        $customer = Customer::find($this->customerId);
        if (! $customer) {
            WorkStatus::update($this->customerId, 'seo-audit', $this->workRunId, 'failed', 'The account or website is no longer available.');

            return;
        }

        try {
            $service = new SeoAuditService($customer);
            $audit = $service->audit($this->url);

            WorkStatus::update($this->customerId, 'seo-audit', $this->workRunId, 'completed', 'Audit results are ready.');
            Log::info('RunSeoAudit: Complete', [
                'customer_id' => $this->customerId,
                'url' => $this->url,
                'score' => $audit->score,
            ]);
        } catch (\Throwable $e) {
            report($e);
            Log::error('RunSeoAudit: Failed', [
                'customer_id' => $this->customerId,
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        WorkStatus::update($this->customerId, 'seo-audit', $this->workRunId, 'failed', 'This request could not finish. Retry using the same form.');
        Log::error('RunSeoAudit failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
