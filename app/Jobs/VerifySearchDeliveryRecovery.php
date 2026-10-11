<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Notifications\CriticalAgentAlert;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBiddingStrategy;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use Google\Ads\GoogleAds\V22\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/** Prove a bounded Search recovery experiment worked, then end it after seven days. */
class VerifySearchDeliveryRecovery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $campaignId,
        public int $cpcCeilingMicros,
        public string $phase = 'initial'
    ) {}

    public function handle(): void
    {
        $lock = Cache::lock("search_recovery:verify:{$this->campaignId}:{$this->phase}", 120);
        if (! $lock->get()) {
            return;
        }

        try {
            $campaign = Campaign::with('customer')->findOrFail($this->campaignId);
            if ($campaign->status !== CampaignStatus::Active || ! $campaign->customer?->google_ads_customer_id) {
                return;
            }
            if (CampaignSpendGuardrails::automaticChangesSuspended($campaign) || $campaign->hasPassedEndDate()) {
                return; // A delayed legacy job cannot undo a newer approved trial or spending hold.
            }

            $startedAt = AgentActivity::where('campaign_id', $campaign->id)
                ->where('action', 'search_recovery_started')->latest('id')->value('created_at');
            $snapshot = $this->inspector($campaign->customer)->inspect($campaign,
                $startedAt ? \Carbon\CarbonImmutable::parse($startedAt) : null);
            if (! $snapshot || $snapshot['bidding_strategy'] !== BiddingStrategyType::TARGET_SPEND) {
                return; // An operator changed the strategy; never overwrite that decision.
            }

            $impressions = $snapshot['measurement']['impressions'] ?? ($snapshot['impressions']['google_search'] ?? 0);
            if ($this->phase === 'initial' && $impressions > 0) {
                AgentActivity::record('self_healing', 'search_recovery_verified',
                    'Google Search impressions resumed for "'.$campaign->name.'"',
                    $campaign->customer_id, $campaign->id,
                    ['impressions' => $snapshot['impressions'], 'auction' => $snapshot['auction']]);
                self::dispatch($campaign->id, $this->cpcCeilingMicros, 'final')->delay(now()->addDays(5));

                return;
            }

            $reason = $this->phase === 'initial'
                ? 'The capped experiment still produced zero Google Search impressions after 48 hours.'
                : 'The seven-day capped traffic experiment has ended.';
            $this->restorePreviousBidding($campaign);

            $action = $this->phase === 'initial' ? 'search_recovery_failed' : 'search_recovery_completed';
            AgentActivity::record('self_healing', $action,
                $reason.' Restored conversion bidding for "'.$campaign->name.'"',
                $campaign->customer_id, $campaign->id,
                ['impressions' => $snapshot['impressions'], 'auction' => $snapshot['auction'],
                    'cpc_ceiling_micros' => $this->cpcCeilingMicros]);

            if ($this->phase === 'initial' || ($snapshot['auction']['conversions'] ?? 0) == 0) {
                CriticalAgentAlert::deliver('search_delivery_recovery',
                    'Search recovery needs review: '.$campaign->name,
                    $reason.' Review Ad Rank, keyword reach, ad relevance, landing page and conversion tracking. '.
                    'The approved daily budget was not increased.',
                    ['campaign_id' => $campaign->id, 'customer_id' => $campaign->customer_id,
                        'snapshot' => $snapshot],
                    CriticalAgentAlert::RECIPIENTS_ADMINS);
            }
        } finally {
            $lock->release();
        }
    }

    private function restorePreviousBidding(Campaign $campaign): void
    {
        $customer = $campaign->customer;
        $updater = $this->updater($customer);
        $customerId = $customer->cleanGoogleCustomerId();
        if (! $updater->dryRun()->__invoke($customerId, $campaign->google_ads_campaign_id, 'MAXIMIZE_CONVERSIONS')
            || ! $updater->dryRun(false)->__invoke($customerId, $campaign->google_ads_campaign_id, 'MAXIMIZE_CONVERSIONS')) {
            throw new \RuntimeException('Google rejected restoration of conversion bidding for campaign '.$campaign->id);
        }

        $campaign->update(['last_bidding_changed_at' => now()]);
        $strategy = $campaign->strategies()->latest()->first();
        if ($strategy) {
            $data = $strategy->bidding_strategy ?? [];
            $data['name'] = 'MaximizeConversions';
            $data['bid_strategy'] = 'MAXIMIZE_CONVERSIONS';
            $strategy->update(['bidding_strategy' => $data]);
        }
    }

    protected function inspector(Customer $customer): InspectSearchDelivery
    {
        return new InspectSearchDelivery($customer);
    }

    protected function updater(Customer $customer): UpdateCampaignBiddingStrategy
    {
        return new UpdateCampaignBiddingStrategy($customer);
    }
}
