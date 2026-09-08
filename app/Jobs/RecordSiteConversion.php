<?php

namespace App\Jobs;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fans a server-side own-site conversion (campaign_live, seven_day_return) out
 * to one RecordSiteGoogleConversion per user who arrived from a Google Ad.
 *
 * This job used to perform the upload itself, and it picked its users with
 * `whereNotNull('gclid')` before calling the gclid-only Data Manager wrapper.
 * Google sends gbraid or wbraid *instead of* a gclid wherever iOS ATT applies,
 * so every iOS conversion on these two events was dropped — silently, and on
 * exactly the bottom-of-funnel signals Maximize Conversions bids from.
 *
 * RecordSiteGoogleConversion already walks gclid → gbraid → wbraid and is the
 * job both signup paths use, so there is no second upload implementation here
 * any more: this is the customer → user fan-out and nothing else.
 */
class RecordSiteConversion implements ShouldQueue
{
    /**
     * A soft-deleted customer/campaign mid-queue means the work is moot —
     * discard quietly instead of filling failed_jobs with ModelNotFound.
     */
    public $deleteWhenMissingModels = true;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected Customer $customer,
        protected string $event
    ) {}

    public function handle(): void
    {
        // Fixed here rather than left to each child job: these events happen
        // now, and Google attributes on the conversion timestamp, so a retry
        // tomorrow must not record the conversion as happening tomorrow.
        // (`signup` is the event that did not need this — it keeps
        // RecordSiteGoogleConversion's default of the registration time.)
        $occurredAt = now();

        foreach ($this->customer->users as $user) {
            // The child job returns early without an identifier anyway; this
            // only keeps a no-op job off the queue for every teammate on the
            // account who did not arrive from an ad.
            if (! $user->hasGoogleClickId()) {
                continue;
            }

            RecordSiteGoogleConversion::dispatch($user, $this->event, $occurredAt);
        }
    }
}
