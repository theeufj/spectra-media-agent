<?php

namespace App\Jobs;

use App\Mail\CROAuditComplete;
use App\Models\Customer;
use App\Services\GeminiService;
use App\Services\LandingPageCROAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RunCroAudit implements ShouldQueue
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

        if (! $customer) {
            Log::warning('RunCroAudit: Customer not found', ['customer_id' => $this->customerId]);

            return;
        }

        try {
            Log::info('RunCroAudit: Rendering page', ['url' => $this->url]);

            $html = app(\App\Services\Crawling\WebsiteRenderer::class)->html($this->url);

            $service = new LandingPageCROAuditService(new GeminiService);
            $audit = $service->auditPage($customer, $this->url, $html);

            $customer->increment('cro_audits_used');

            Log::info('RunCroAudit: Completed', [
                'customer_id' => $this->customerId,
                'url' => $this->url,
                'score' => $audit->overall_score,
            ]);

            // Everyone on the account, not whoever the query returned first —
            // the same fan-out the rest of the product does.
            foreach ($customer->users as $user) {
                Mail::to($user)->send(new CROAuditComplete($user, $audit, count($audit->issues ?? [])));
            }
        } catch (\Throwable $e) {
            Log::error('RunCroAudit: Failed', [
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
        Log::error('RunCroAudit failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
