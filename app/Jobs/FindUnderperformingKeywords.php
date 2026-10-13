<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\AccountStructureService;
use App\Services\GoogleAds\NegativeKeywords\AddNegativeKeywordService;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FindUnderperformingKeywords implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $campaignId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $campaignId)
    {
        $this->campaignId = $campaignId;
    }

    /**
     * Execute the job.
     */
    public function handle(AddNegativeKeywordService $addNegativeKeywordService): void
    {
        try {
            $campaign = Campaign::with('customer')->findOrFail($this->campaignId);
            if (CampaignSpendGuardrails::automaticChangesSuspended($campaign)) {
                Log::info('FindUnderperformingKeywords: Automatic keyword changes suspended by trial or spending hold', ['campaign_id' => $campaign->id]);

                return;
            }
            if (! $campaign->customer || $campaign->customer->is_sandbox || $campaign->customer->service_type !== 'managed'
                || $campaign->status !== CampaignStatus::Active || $campaign->hasPassedEndDate()
                || ! empty($campaign->search_delivery_state['repair']['errors']) || ! empty($campaign->search_delivery_state['verification']['errors'])) {
                AgentActivity::record('keyword', 'keyword_exclusions_need_review',
                    'Automatic keyword exclusions are held while the campaign or its previous reach changes need review.',
                    $campaign->customer_id, $campaign->id, [], 'needs_review');

                return;
            }

            // PMax campaigns don't have keywords — skip entirely.
            //
            // `platform` is a free-text label written when the strategy is
            // generated, and it drifts: campaign 31 was stored as
            // "Google Ads (SEM)" for a campaign Google reports as
            // PERFORMANCE_MAX. `campaign_type` is the structured field and was
            // correct in that case, so match on either. A PMax campaign that
            // slips past this guard queries keyword_view, which cannot return
            // anything for PMax — wasted API calls dressed up as a clean run.
            $isPMax = $campaign->strategies()
                ->where(function ($query) {
                    $query->where('platform', 'LIKE', '%Performance Max%')
                        ->orWhere('campaign_type', 'performance_max');
                })
                ->exists();
            if ($isPMax) {
                Log::info("FindUnderperformingKeywords: skipping PMax campaign {$this->campaignId}");

                return;
            }

            $campaignId = $campaign->googleCampaignNumericId();
            $customerId = $campaign->customer->cleanGoogleCustomerId();
            if (! $campaignId || ! preg_match('~^customers/'.preg_quote($customerId, '~').'/campaigns/\d+$~', $campaign->googleAdsResourceName() ?? '')) {
                throw new \RuntimeException('The campaign resource does not match the Google customer account.');
            }
            $query = "SELECT ad_group_criterion.keyword.text, metrics.clicks, metrics.conversions
                      FROM keyword_view WHERE campaign.id = {$campaignId}
                      AND campaign.advertising_channel_type = SEARCH
                      AND ad_group_criterion.status = ENABLED AND ad_group.status = ENABLED
                      AND segments.date DURING LAST_30_DAYS";
            $performance = [];
            foreach ($this->keywordRows($campaign, $query) as $googleAdsRow) {
                $text = trim($googleAdsRow->getAdGroupCriterion()->getKeyword()->getText());
                if ($text === '') {
                    continue;
                }
                $key = mb_strtolower($text);
                $performance[$key] ??= ['text' => $text, 'clicks' => 0, 'conversions' => 0.0];
                $performance[$key]['clicks'] += (int) $googleAdsRow->getMetrics()->getClicks();
                $performance[$key]['conversions'] += $googleAdsRow->getMetrics()->getConversions();
            }
            // A campaign-wide exclusion must not erase a converting instance
            // of the same text in another ad group or match type.
            $keywords = array_column(array_filter($performance,
                fn ($row) => $row['clicks'] > 100 && $row['conversions'] <= 0), 'text');
            $addNegativeKeywordService->applyBatch($this->campaignId, $keywords);
        } catch (\Throwable $e) {
            report($e);
            Log::error("Error finding underperforming keywords for campaign {$this->campaignId}: ".$e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    protected function keywordRows(Campaign $campaign, string $query): iterable
    {
        $service = new AccountStructureService($campaign->customer);

        return $service->getClient()->getGoogleAdsServiceClient()->search(new SearchGoogleAdsRequest([
            'customer_id' => $campaign->customer->cleanGoogleCustomerId(), 'query' => $query,
        ]))->iterateAllElements();
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('FindUnderperformingKeywords failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
