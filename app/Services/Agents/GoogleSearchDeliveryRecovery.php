<?php

namespace App\Services\Agents;

use App\Features\AutoHealing;
use App\Jobs\VerifySearchDeliveryRecovery;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBiddingStrategy;
use Google\Ads\GoogleAds\V22\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;

/** One capped traffic-bootstrap attempt; repeated stalls escalate to people. */
class GoogleSearchDeliveryRecovery
{
    public function bootstrap(Campaign $campaign, array $snapshot): array
    {
        $lock = Cache::lock("search_recovery:start:{$campaign->id}", 120);
        if (! $lock->get()) {
            return ['started' => false, 'reason' => 'another recovery pass is already running'];
        }

        try {
            return $this->bootstrapLocked($campaign, $snapshot);
        } finally {
            $lock->release();
        }
    }

    private function bootstrapLocked(Campaign $campaign, array $snapshot): array
    {
        $customer = $campaign->customer;
        if (! $customer || $customer->service_type === 'setup_only'
            || $customer->google_ads_link_status === 'revoked'
            || ! Feature::for($customer)->active(AutoHealing::class)) {
            return ['started' => false, 'reason' => 'account is not eligible for automatic management'];
        }

        if ($snapshot['bidding_strategy'] !== BiddingStrategyType::MAXIMIZE_CONVERSIONS
            || ($snapshot['target_cpa_micros'] ?? null) !== 0
            || ! $snapshot['google_search_enabled']
            || $snapshot['eligible_keywords'] < 1
            || $snapshot['approved_ads'] < 1
            || ($snapshot['auction']['conversions'] ?? 0) > 0
            || ($snapshot['auction']['lost_to_rank'] ?? 0) < 0.9001
            || ($snapshot['auction']['lost_to_budget'] ?? 0) > 0) {
            return ['started' => false, 'reason' => 'delivery evidence does not support a capped bid experiment'];
        }

        if (AgentActivity::query()->where('campaign_id', $campaign->id)
            ->where('action', 'search_recovery_started')
            ->where('created_at', '>=', now()->subDays(30))->exists()) {
            return ['started' => false, 'reason' => 'recovery has already been attempted in the last 30 days'];
        }

        $approvedMicros = self::moneyToMicros($campaign->approved_daily_budget);
        $currentMicros = (int) ($snapshot['daily_budget_micros'] ?? 0);
        if ($approvedMicros < 1_000_000 || $currentMicros < 1_000_000 || $currentMicros > $approvedMicros) {
            return ['started' => false, 'reason' => 'the live daily budget is not within the approved limit'];
        }

        // Only an observed CPC informs the initial cap. No invented market bid.
        $clicks = (int) ($snapshot['auction']['clicks'] ?? 0);
        $costMicros = (int) ($snapshot['auction']['cost_micros'] ?? 0);
        if ($clicks < 1 || $costMicros < 1) {
            return ['started' => false, 'reason' => 'no observed CPC exists to set a defensible bid ceiling'];
        }
        $ceiling = min(intdiv($currentMicros, 5), intdiv($costMicros * 3, $clicks * 2));
        if ($ceiling < 1_000_000) {
            return ['started' => false, 'reason' => 'safe bid ceiling is below one account-currency unit'];
        }

        $customerId = $customer->cleanGoogleCustomerId();
        $campaignName = $campaign->google_ads_campaign_id;
        $updater = $this->updater($customer);
        if (! $updater->dryRun()->__invoke($customerId, $campaignName, 'MAXIMIZE_CLICKS', null, null, $ceiling)) {
            return ['started' => false, 'reason' => 'Google rejected the proposed strategy in validate-only mode'];
        }

        // Persist the verification job before changing Google. If a local write
        // fails after the remote mutation, the experiment still gets reverted.
        VerifySearchDeliveryRecovery::dispatch($campaign->id, $ceiling)->delay(now()->addHours(48));
        if (! $updater->dryRun(false)->__invoke($customerId, $campaignName, 'MAXIMIZE_CLICKS', null, null, $ceiling)) {
            return ['started' => false, 'reason' => 'Google rejected the live bidding change'];
        }

        $campaign->update(['last_bidding_changed_at' => now()]);
        $strategy = $campaign->strategies()->latest()->first();
        if ($strategy) {
            $data = $strategy->bidding_strategy ?? [];
            $data['name'] = 'MaximizeClicks';
            $data['bid_strategy'] = 'MAXIMIZE_CLICKS';
            $strategy->update(['bidding_strategy' => $data]);
        }

        AgentActivity::record('self_healing', 'search_recovery_started',
            'Started capped Search traffic recovery for "'.$campaign->name.'"',
            $campaign->customer_id, $campaign->id,
            ['previous_strategy' => 'MAXIMIZE_CONVERSIONS', 'new_strategy' => 'MAXIMIZE_CLICKS',
                'cpc_ceiling_micros' => $ceiling, 'daily_budget_micros' => $currentMicros]);

        return ['started' => true, 'cpc_ceiling_micros' => $ceiling, 'daily_budget_micros' => $currentMicros];
    }

    private static function moneyToMicros(mixed $amount): int
    {
        $value = (string) $amount;
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            return 0;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100 + (int) str_pad($fraction, 2, '0')) * 10_000;
    }

    protected function updater(Customer $customer): UpdateCampaignBiddingStrategy
    {
        return new UpdateCampaignBiddingStrategy($customer);
    }
}
