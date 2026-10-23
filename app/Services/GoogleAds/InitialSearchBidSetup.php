<?php

namespace App\Services\GoogleAds;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionBudgetGuard;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\GoogleSearchReachGate;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\UpdateAdGroupCpcBid;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus as GoogleStatus;
use Illuminate\Support\Facades\DB;

/** Initial bid configuration only. It cannot activate ads or repair a running campaign. */
class InitialSearchBidSetup
{
    public function __construct(protected Customer $customer) {}

    public function configure(Campaign $campaign, Strategy $strategy, string $customerId, string $resource, string $adGroup, ExecutionResult $result): bool
    {
        try {
            $this->authorize($campaign, $strategy, $customerId, $resource, $adGroup);
            $snapshot = $this->snapshot($campaign, $resource);
            $this->authorize($campaign, $strategy, $customerId, $resource, $adGroup);
            $this->assertPaused($snapshot);
            if (($snapshot['bidding_strategy'] ?? null) !== BiddingStrategyType::MANUAL_CPC) {
                $result->addMetadata('initial_search_bid', ['status' => 'automated_bidding_preserved', 'activation_ready' => false]);

                return true;
            }
            $keywords = $snapshot['keywords'] ?? [];
            if ($keywords === [] || array_filter($keywords, fn ($keyword) => ($keyword['ad_group_resource'] ?? null) !== $adGroup)) {
                throw new \RuntimeException('Initial bid setup requires the verified keyword set in this strategy’s own ad group.');
            }
            $snapshot = $this->withApproval($snapshot, $campaign, $strategy, $resource);
            $fingerprint = $this->fingerprint($snapshot);
            $prior = $strategy->fresh()->execution_result['metadata']['initial_search_bid'] ?? [];
            if (in_array($prior['status'] ?? '', ['verified', 'write_pending', 'needs_review'], true)
                && isset($prior['attempted_at'])
                && ($prior['campaign_resource'] ?? null) === $resource && ($prior['ad_group_resource'] ?? null) === $adGroup
                && ($prior['settings_fingerprint'] ?? null) === $fingerprint
                && $this->matchesBid($snapshot, (int) ($prior['cpc_bid_micros'] ?? 0))) {
                $prior['status'] = 'verified';
                $prior['verified_at'] = now()->toIso8601String();
                $this->save($campaign, $strategy, $customerId, $resource, $adGroup, $prior);
                $result->addMetadata('initial_search_bid', $prior);

                return true;
            }
            // Never reinterpret a custom/explicit owner bid as an unset provider
            // default. Its reach is checked by the separate activation gate.
            if (array_filter($keywords, fn ($keyword) => (int) ($keyword['keyword_cpc_bid_micros'] ?? 0) > 0
                || (int) ($keyword['ad_group_cpc_bid_micros'] ?? 0) > 10_000)) {
                $result->addMetadata('initial_search_bid', ['status' => 'existing_bid_preserved', 'activation_ready' => false,
                    'campaign_resource' => $resource, 'ad_group_resource' => $adGroup]);
                $result->addWarning('initial_search_bid_preserved', 'Existing explicit bids were preserved. Current reach still needs verification before activation.');

                return true;
            }

            $plan = app(InitialSearchBidPlanner::class)->plan($campaign, $snapshot);
            $result->addMetadata('initial_search_bid', $plan);
            if (($plan['ready'] ?? false) !== true || ! is_int($plan['cpc_bid_micros'] ?? null) || $plan['cpc_bid_micros'] <= 0) {
                $result->addError('initial_search_bid_needs_review', $plan['reason'] ?? 'Keyword cost and reach estimates could not establish an initial bid within the approved budget. Review bidding before retrying.');

                return false;
            }
            $bid = $plan['cpc_bid_micros'];
            $this->authorize($campaign, $strategy, $customerId, $resource, $adGroup);
            $current = $this->withApproval($this->snapshot($campaign, $resource), $campaign, $strategy, $resource);
            $this->authorize($campaign, $strategy, $customerId, $resource, $adGroup);
            $this->assertPaused($current);
            if ($this->fingerprint($current) !== $fingerprint || $bid > $current['authorized_daily_budget_micros']
                || $bid % 10_000 !== 0 || ($current['approved_cpc_ceiling_micros'] !== null && $bid > $current['approved_cpc_ceiling_micros'])) {
                throw new \RuntimeException('The approved budget, bid limits or Google settings changed during initial bid research. Retry with current settings.');
            }
            $expected = $this->withBid($current, $bid);
            $pending = [...$plan, 'status' => 'write_pending', 'activation_ready' => false,
                'campaign_resource' => $resource, 'ad_group_resource' => $adGroup,
                'authorized_daily_budget_micros' => $current['authorized_daily_budget_micros'],
                'approved_cpc_ceiling_micros' => $current['approved_cpc_ceiling_micros'],
                'reviewed_settings_fingerprint' => $current['reviewed_settings_fingerprint'],
                'settings_fingerprint' => $this->fingerprint($expected), 'attempted_at' => now()->toIso8601String()];
            $this->save($campaign, $strategy, $customerId, $resource, $adGroup, $pending);
            $result->addMetadata('initial_search_bid', $pending);
            // Research and the full readback may be slow. The last remote check
            // and fresh local authorization immediately precede the only write.
            $status = app(GetCampaignStatus::class, ['customer' => $this->customer])($customerId, $resource);
            if (($status['status'] ?? null) !== GoogleStatus::PAUSED) {
                throw new \RuntimeException('Google no longer confirms a paused campaign. No initial bid was written.');
            }
            $this->authorize($campaign, $strategy, $customerId, $resource, $adGroup);
            if ($this->fingerprint($this->withApproval($current, $campaign, $strategy, $resource)) !== $fingerprint) {
                throw new \RuntimeException('The approved allocation or CPC limit changed before the bid write. Repeat initial bid research.');
            }
            if (! app(UpdateAdGroupCpcBid::class, ['customer' => $this->customer])($customerId, $adGroup, $bid)) {
                throw new \RuntimeException('Google did not acknowledge the initial bid update. Review its current settings before retrying.');
            }
            $readback = $this->withApproval($this->snapshot($campaign, $resource), $campaign, $strategy, $resource);
            $this->authorize($campaign, $strategy, $customerId, $resource, $adGroup);
            $this->assertPaused($readback);
            if (! $this->matchesBid($readback, $bid) || $this->fingerprint($readback) !== $this->fingerprint($expected)) {
                throw new \RuntimeException('The new bid and otherwise unchanged paused settings could not be verified in Google. Review the current campaign.');
            }
            $verified = [...$pending, 'status' => 'verified', 'verified_at' => now()->toIso8601String(),
                'settings_fingerprint' => $this->fingerprint($readback)];
            $this->save($campaign, $strategy, $customerId, $resource, $adGroup, $verified);
            $result->addMetadata('initial_search_bid', $verified);

            return true;
        } catch (\Throwable $e) {
            report($e);
            $result->addMetadata('initial_search_bid', [...($result->metadata['initial_search_bid'] ?? []),
                'status' => 'needs_review', 'ready' => false, 'activation_ready' => false, 'reason' => $e->getMessage()]);
            $result->addError('initial_search_bid_unverified', $e->getMessage());

            return false;
        }
    }

    /** @phpstan-impure Fresh authorization is repeated after every slow provider read. */
    private function authorize(Campaign $campaign, Strategy $strategy, string $customerId, string $resource, string $adGroup): void
    {
        $current = $campaign->fresh()?->load('customer');
        $selected = $strategy->fresh();
        if (! $current || ! $selected || $current->customer_id !== $this->customer->id || $selected->campaign_id !== $current->id
            || $current->customer?->cleanGoogleCustomerId() !== $customerId || $current->customer->is_sandbox
            || ($current->customer->service_type === 'setup_only' && ! $current->customer->isPaidSetupOnly())
            || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#', $resource)
            || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/adGroups/\d+$#', $adGroup)
            || $this->resource($selected, $customerId) !== $resource || $selected->google_ads_ad_group_id !== $adGroup
            || in_array($current->customer->google_ads_link_status, ['pending', 'refused', 'cancelled', 'failed', 'revoked'], true)
            || ! $current->budget_confirmed_at || $current->spend_safety_hold || CampaignSpendGuardrails::activeTrial($current)
            || $current->hasPassedEndDate() || in_array($current->status, [CampaignStatus::Ended, CampaignStatus::Completed], true)) {
            throw new \RuntimeException('Current ownership, confirmed funding, account access or spending safeguards do not permit initial bid setup.');
        }
        $budget = app(ExecutionBudgetGuard::class)->validate(new ExecutionPlan([]), new ExecutionContext($selected, $current, $current->customer), 'google');
        if (! $budget->passed) {
            throw new \RuntimeException('Current strategy allocations do not fit the approved campaign daily budget.');
        }
    }

    private function snapshot(Campaign $campaign, string $resource): array
    {
        $staged = $campaign->fresh()->load('customer');
        $staged->google_ads_campaign_id = $resource;
        $snapshot = app(InspectSearchDelivery::class, ['customer' => $this->customer])->inspect($staged);
        if (! $snapshot) {
            throw new \RuntimeException('Google did not return the staged Search settings for bid setup.');
        }

        return $snapshot;
    }

    private function assertPaused(array $snapshot): void
    {
        if (($snapshot['status'] ?? null) !== GoogleStatus::PAUSED) {
            throw new \RuntimeException('Initial bid setup requires a campaign freshly verified paused in Google.');
        }
    }

    private function withApproval(array $snapshot, Campaign $campaign, Strategy $strategy, string $resource): array
    {
        $current = $campaign->fresh();
        $selected = $strategy->fresh();
        [$approved, $cap] = $this->approval($current, $selected);
        if (($snapshot['daily_budget_micros'] ?? 0) <= 0 || $snapshot['daily_budget_micros'] > $approved
            || ($snapshot['currency_code'] ?? null) !== $current->customer->currency_code) {
            throw new \RuntimeException('The live Google budget or account currency does not match the approved strategy allocation.');
        }

        return [...$snapshot, 'authorized_daily_budget_micros' => $approved, 'approved_cpc_ceiling_micros' => $cap,
            'reviewed_settings_fingerprint' => $this->reviewedSettings($current, $selected),
            'campaign_resource' => $resource, 'strategy_id' => $strategy->id];
    }

    private function approval(Campaign $campaign, Strategy $strategy): array
    {
        $approved = min(CampaignSpendGuardrails::moneyToMicros($campaign->approved_daily_budget),
            CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget));
        $cap = $strategy->bidding_strategy['parameters']['cpcBidCeilingMicros'] ?? $strategy->bidding_strategy['cpc_bid_ceiling_micros'] ?? null;
        if ($cap !== null && (! is_numeric($cap) || (int) $cap <= 0 || (string) (int) $cap !== (string) $cap)) {
            throw new \RuntimeException('The saved CPC ceiling is invalid and needs review.');
        }
        $initial = $strategy->bidding_strategy['parameters']['initialCpcBidMicros'] ?? null;
        if ($initial !== null && (! is_int($initial) || $initial <= 0 || $initial % 10_000 !== 0)) {
            throw new \RuntimeException('The approved initial CPC proposal is invalid and needs review.');
        }
        // A new observation may justify a lower starting bid. It cannot grant
        // more than either the accepted initial proposal or the owner's cap.
        $cap = $cap === null ? $initial : ($initial === null ? (int) $cap : min((int) $cap, $initial));

        return [$approved, $cap];
    }

    private function reviewedSettings(Campaign $campaign, Strategy $strategy): string
    {
        return hash('sha256', json_encode([$campaign->getRawOriginal('start_date'), $campaign->getRawOriginal('end_date'),
            $campaign->keywords, $strategy->bidding_strategy], JSON_THROW_ON_ERROR));
    }

    private function matchesBid(array $snapshot, int $bid): bool
    {
        return $bid > 0 && ($snapshot['keywords'] ?? []) !== []
            && ! array_filter($snapshot['keywords'], fn ($keyword) => ($keyword['cpc_bid_micros'] ?? null) !== $bid
                || ($keyword['ad_group_cpc_bid_micros'] ?? null) !== $bid || ($keyword['keyword_cpc_bid_micros'] ?? null) !== 0);
    }

    private function withBid(array $snapshot, int $bid): array
    {
        $snapshot['actual_cpc_ceiling_micros'] = $snapshot['cpc_bid_ceiling_micros'] = $bid;
        foreach ($snapshot['keywords'] as &$keyword) {
            $keyword['ad_group_cpc_bid_micros'] = $keyword['cpc_bid_micros'] = $bid;
        }

        return $snapshot;
    }

    private function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode([GoogleSearchReachGate::settingsFingerprint($snapshot),
            array_map(fn ($keyword) => array_intersect_key($keyword, array_flip(['resource_name', 'keyword_cpc_bid_micros', 'ad_group_cpc_bid_micros'])), $snapshot['keywords'] ?? []),
            $snapshot['authorized_daily_budget_micros'], $snapshot['approved_cpc_ceiling_micros'],
            $snapshot['reviewed_settings_fingerprint']], JSON_THROW_ON_ERROR));
    }

    private function resource(Strategy $strategy, string $customerId): ?string
    {
        $resource = $strategy->reusableGoogleCampaignId();

        return $resource && ctype_digit($resource) ? "customers/{$customerId}/campaigns/{$resource}" : $resource;
    }

    private function save(Campaign $campaign, Strategy $strategy, string $customerId, string $resource, string $adGroup, array $evidence): void
    {
        DB::transaction(function () use ($campaign, $strategy, $customerId, $resource, $adGroup, $evidence): void {
            $current = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $selected = Strategy::whereKey($strategy->id)->lockForUpdate()->firstOrFail();
            $this->authorize($campaign, $selected, $customerId, $resource, $adGroup);
            [$budget, $cap] = $this->approval($current, $selected);
            if (($evidence['authorized_daily_budget_micros'] ?? null) !== $budget
                || ($evidence['approved_cpc_ceiling_micros'] ?? null) !== $cap
                || ($evidence['reviewed_settings_fingerprint'] ?? null) !== $this->reviewedSettings($current, $selected)) {
                throw new \RuntimeException('The approved spending context changed before initial bid evidence could be saved.');
            }
            $execution = $selected->execution_result ?? [];
            $execution['metadata']['initial_search_bid'] = $evidence;
            $selected->update(['execution_result' => $execution]);
        });
    }
}
