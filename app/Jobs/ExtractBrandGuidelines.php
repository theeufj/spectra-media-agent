<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\CustomerPage;
use App\Services\BrandGuidelineExtractorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractBrandGuidelines implements ShouldQueue
{
    /**
     * A soft-deleted customer/campaign mid-queue means the work is moot —
     * discard quietly instead of filling failed_jobs with ModelNotFound.
     */
    public $deleteWhenMissingModels = true;

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
    /**
     * $force bypasses the freshness skip below: it exists for the explicit
     * "Re-analyze Website" button, where the user asked for a fresh run and
     * a silent no-op reads as the feature being broken.
     */
    public function __construct(
        protected Customer $customer,
        protected bool $force = false,
    ) {}

    /**
     * The onboarding chain dispatches this from every batch that finishes
     * (one per child sitemap, plus the navigation-gap batch), so several
     * copies race. Without this, each ran a full Gemini extraction — and the
     * late ones hit the extractor's rate-limit guard, got null back, and
     * emailed the customer a scan FAILURE minutes after the scan succeeded.
     */
    public function middleware(): array
    {
        return [
            (new \Illuminate\Queue\Middleware\WithoutOverlapping('extract-brand-'.$this->customer->id))
                ->releaseAfter(120)
                ->expireAfter(600),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(BrandGuidelineExtractorService $extractor): void
    {
        Log::info("ExtractBrandGuidelines job started for customer {$this->customer->id}");

        // A fresh guideline means another copy of this job already completed
        // the whole chain — emails, asset harvest, first campaign. Re-running
        // would duplicate all of it.
        $existing = $this->customer->brandGuideline;
        if (! $this->force && $existing && $existing->created_at->gt(now()->subHour())) {
            Log::info('ExtractBrandGuidelines: fresh guideline already exists, skipping duplicate run', [
                'customer_id' => $this->customer->id,
                'guideline_id' => $existing->id,
            ]);

            return;
        }

        try {
            $brandGuideline = $extractor->extractGuidelines($this->customer);

            if ($brandGuideline) {
                Log::info('Brand guidelines extracted successfully', [
                    'customer_id' => $this->customer->id,
                    'guideline_id' => $brandGuideline->id,
                    'quality_score' => $brandGuideline->extraction_quality_score,
                ]);

                $totalPages = CustomerPage::where('customer_id', $this->customer->id)->count();

                // The crawl just filled customer_pages, so harvest the site's
                // own imagery now — classified product/lifestyle shots become
                // deployable collateral and the seed pool image generation
                // falls back to. This used to exist only behind a manual
                // button buried in the collateral page, so onboarding never
                // produced it. Idempotent: the job skips already-harvested
                // source URLs.
                HarvestWebsiteAssets::dispatch($this->customer);

                // Everything needed to write their first campaign is now known:
                // brand voice, audience, messaging, and every page they sell
                // from. Asking them to describe that business back to us in a
                // nine-step wizard is why sixteen accounts were crawled and
                // none of them built anything.
                //
                // The job decides for itself whether this account qualifies and
                // never throws — a failure costs the customer a bonus, not
                // their onboarding. It sends its own email when it succeeds, so
                // the crawl notice below is only for accounts that do not
                // qualify.
                if (GenerateFirstCampaign::qualifies($this->customer)) {
                    GenerateFirstCampaign::dispatch($this->customer);

                    return;
                }

                // Notify all users: brand extraction is complete and the
                // knowledge base is ready. Mail plus the in-app bell — the
                // dashboard checklist flips quietly, and "quietly" is how a
                // customer ends up watching a finished scan wondering if it
                // ever ran.
                foreach ($this->customer->users as $user) {
                    $user->notify(new \App\Notifications\SiteScanCompleted(
                        $this->customer,
                        $totalPages,
                    ));
                }
            } else {
                Log::warning('Brand guideline extraction returned null', [
                    'customer_id' => $this->customer->id,
                ]);

                // Null does not always mean failure: a duplicate dispatch that
                // slipped past the freshness check (e.g. the extractor's own
                // rate-limit guard) returns null while a perfectly good
                // guideline sits in the table. Only report failure when the
                // customer actually has nothing.
                if ($this->customer->brandGuideline()->exists()) {
                    Log::info('ExtractBrandGuidelines: extraction returned null but a guideline exists — not a failure', [
                        'customer_id' => $this->customer->id,
                    ]);

                    return;
                }

                // This is the end of the onboarding chain — nothing downstream
                // runs without a brand guideline, so tell the user now rather
                // than leaving them on "we're scanning your website".
                foreach ($this->customer->users as $user) {
                    $user->notify(new \App\Notifications\SiteScanFailed(
                        $this->customer,
                        'We scanned your site but couldn\'t extract enough about your brand to build campaigns from.'
                    ));
                }
            }

        } catch (\Exception $e) {
            Log::error('ExtractBrandGuidelines job failed', [
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
        Log::error('ExtractBrandGuidelines job failed permanently', [
            'customer_id' => $this->customer->id,
            'error' => $exception->getMessage(),
        ]);

        // Out of retries — the onboarding chain ends here, so the user must
        // hear about it from us rather than from silence.
        try {
            foreach ($this->customer->users as $user) {
                $user->notify(new \App\Notifications\SiteScanFailed(
                    $this->customer,
                    'Our analysis of your site kept failing partway through.'
                ));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
