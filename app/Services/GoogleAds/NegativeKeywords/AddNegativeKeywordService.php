<?php

namespace App\Services\GoogleAds\NegativeKeywords;

use App\Models\Campaign;
use App\Models\NegativeKeywordList;
use App\Services\GoogleAds\CommonServices\AddNegativeKeyword;
use Illuminate\Support\Facades\Log;

/**
 * Record a negative keyword and apply it to the campaign on Google.
 *
 * Two things were wrong here, and each hid the other.
 *
 * The local write used firstOrCreate(['campaign_id' => ...]) against
 * negative_keyword_lists, which has no campaign_id column — lists are scoped to
 * a customer — and then wrote through a keywords() relation the model does not
 * define. Either would have thrown on the first call.
 *
 * More importantly, nothing here ever reached Google. FindUnderperformingKeywords
 * exists to stop paying for keywords with clicks and no conversions, and it
 * called this service for each one. Even had the local write worked, the keyword
 * would have kept serving and kept costing money, because excluding it in our
 * database excludes it nowhere that matters.
 */
class AddNegativeKeywordService
{
    public function __invoke(int $campaignId, string $keyword): void
    {
        try {
            $campaign = Campaign::with('customer')->find($campaignId);

            if (! $campaign || ! $campaign->customer) {
                Log::warning("AddNegativeKeyword: campaign {$campaignId} not found");

                return;
            }

            $this->applyToGoogle($campaign, $keyword);
            $this->recordLocally($campaign, $keyword);
        } catch (\Throwable $e) {
            // report() as well as log: a negative keyword that fails to apply is
            // spend that carries on, and this runs unattended overnight.
            report($e);
            Log::error("Error adding negative keyword for campaign {$campaignId}: ".$e->getMessage());
        }
    }

    /**
     * Exclude the keyword on the campaign itself. This is the part that stops
     * the spend.
     */
    private function applyToGoogle(Campaign $campaign, string $keyword): void
    {
        $resourceName = $campaign->googleAdsResourceName();

        if (! $resourceName || ! $campaign->customer->google_ads_customer_id) {
            Log::info("AddNegativeKeyword: campaign {$campaign->id} is not deployed to Google, recording locally only");

            return;
        }

        $result = (new AddNegativeKeyword($campaign->customer))(
            $campaign->customer->cleanGoogleCustomerId(),
            $resourceName,
            $keyword
        );

        if ($result === null) {
            Log::warning("AddNegativeKeyword: Google rejected '{$keyword}' for campaign {$campaign->id}");

            return;
        }

        Log::info("AddNegativeKeyword: excluded '{$keyword}' on campaign {$campaign->id}");
    }

    /**
     * Keep our own record, so the exclusion is visible in the UI and survives a
     * campaign being rebuilt.
     *
     * Lists are per customer and hold their keywords in a json column — there is
     * a separate negative_keywords table from an earlier design, but nothing
     * reads it.
     */
    private function recordLocally(Campaign $campaign, string $keyword): void
    {
        $list = NegativeKeywordList::firstOrCreate(
            ['customer_id' => $campaign->customer_id, 'name' => 'Auto-excluded'],
            ['keywords' => [], 'applied_to_campaigns' => []]
        );

        $keywords = $list->keywords ?? [];

        if (in_array($keyword, $keywords, true)) {
            return;
        }

        $keywords[] = $keyword;

        $campaigns = $list->applied_to_campaigns ?? [];

        if (! in_array($campaign->id, $campaigns, true)) {
            $campaigns[] = $campaign->id;
        }

        $list->update(['keywords' => $keywords, 'applied_to_campaigns' => $campaigns]);
    }
}
