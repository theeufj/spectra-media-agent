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
use Spatie\Browsershot\Browsershot;

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

        if (!$customer) {
            Log::warning('RunCroAudit: Customer not found', ['customer_id' => $this->customerId]);
            return;
        }

        try {
            Log::info('RunCroAudit: Rendering page', ['url' => $this->url]);

            $html = Browsershot::url($this->url)
                ->setOption('args', ['--no-sandbox', '--disable-setuid-sandbox'])
                ->waitUntilNetworkIdle()
                ->timeout(60)
                ->bodyHtml();

            $service = new LandingPageCROAuditService(new GeminiService());
            $audit = $service->auditPage($customer, $this->url, $html);

            $customer->increment('cro_audits_used');

            Log::info('RunCroAudit: Completed', [
                'customer_id' => $this->customerId,
                'url' => $this->url,
                'score' => $audit->overall_score,
            ]);

            // Send completion email
            $user = $customer->users()->first();
            if ($user) {
                Mail::to($user)->send(new CROAuditComplete($user, $audit, count($audit->issues ?? [])));
            }
        } catch (\Exception $e) {
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
        Log::error('RunCroAudit failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
