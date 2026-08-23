<?php

namespace App\Jobs;

use App\Mail\SitemapCrawlCompleted;
use App\Models\Customer;
use App\Models\CustomerPage;
use App\Services\BrandGuidelineExtractorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

                // Notify all users: brand extraction is complete and the knowledge base is ready
                foreach ($this->customer->users as $user) {
                    Mail::to($user->email)->queue(new SitemapCrawlCompleted(
                        $this->customer->website ?? '',
                        $totalPages,
                        $user->name
                    ));
                }
            } else {
                Log::warning('Brand guideline extraction returned null', [
                    'customer_id' => $this->customer->id,
                ]);

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
