<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\SEO\SeoAuditService;
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
    ) {}

    public function handle(): void
    {
        $customer = Customer::find($this->customerId);
        if (!$customer) return;

        try {
            $service = new SeoAuditService($customer);
            $audit = $service->audit($this->url);

            Log::info('RunSeoAudit: Complete', [
                'customer_id' => $this->customerId,
                'url' => $this->url,
                'score' => $audit->score,
            ]);
        } catch (\Exception $e) {
            Log::error('RunSeoAudit: Failed', [
                'customer_id' => $this->customerId,
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('RunSeoAudit failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
