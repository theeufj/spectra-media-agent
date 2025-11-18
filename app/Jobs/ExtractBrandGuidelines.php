<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\BrandGuidelineExtractorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractBrandGuidelines implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Customer $customer
    ) {}

    /**
     * Execute the job.
     */
    public function handle(BrandGuidelineExtractorService $extractor): void
    {
        Log::info("ExtractBrandGuidelines job started for customer {$this->customer->id}");

        try {
            $brandGuideline = $extractor->extractGuidelines($this->customer);

            if ($brandGuideline) {
                Log::info("Brand guidelines extracted successfully", [
                    'customer_id' => $this->customer->id,
                    'guideline_id' => $brandGuideline->id,
                    'quality_score' => $brandGuideline->extraction_quality_score,
                ]);
            } else {
                Log::warning("Brand guideline extraction returned null", [
                    'customer_id' => $this->customer->id,
                ]);
            }

        } catch (\Exception $e) {
            Log::error("ExtractBrandGuidelines job failed", [
                'customer_id' => $this->customer->id,
                'error' => $e->getMessage(),
            ]);
            
            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ExtractBrandGuidelines job failed permanently", [
            'customer_id' => $this->customer->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
