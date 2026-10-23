<?php

namespace App\Services\Campaigns;

use App\Jobs\ProvisionGoogleAdsAccount;
use App\Jobs\ResearchCampaignMarket;
use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\Agents\ExecutionPlan;
use App\Services\Agents\Google\SearchKeywordBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Research suggests a plan; only an explicit customer approval can adopt it. */
class CampaignMarketResearchWorkflow
{
    public function __construct(private CampaignMarketResearch $research) {}

    public function request(Campaign $campaign, bool $force = false, bool $prepareAccount = false, ?int $dailyCents = null): array
    {
        if ($dailyCents !== null && ($dailyCents < 500 || $dailyCents > 1_000_000)) {
            throw ValidationException::withMessages(['daily_budget' => 'Research a daily budget between 5.00 and 10,000.00.']);
        }
        $requestedMicros = $dailyCents === null ? null : $dailyCents * 10_000;
        $dispatch = false;
        $requestId = DB::transaction(function () use ($campaign, $force, $requestedMicros, &$dispatch): string {
            $current = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $current->setRelation('strategies', $current->strategies()->orderBy('id')->lockForUpdate()->get());
            $fingerprint = $this->research->fingerprint($current, $requestedMicros);
            $previous = $this->stored($current);
            if (! $force && ($previous['input_fingerprint'] ?? null) === $fingerprint
                && (in_array($previous['status'] ?? null, ['queued', 'researching'], true) ? $this->pendingFresh($previous) : $this->fresh($previous))
                && ! empty($previous['request_id'])) {
                return $previous['request_id'];
            }
            $requestId = (string) Str::uuid();
            $this->write($current, [...$previous, 'request_id' => $requestId, 'input_fingerprint' => $fingerprint, 'status' => 'queued',
                'requested_daily_budget_micros' => $requestedMicros,
                'approved_fingerprint' => null, 'approved_at' => null, 'approved_request_id' => null,
                'message' => null,
                'requested_at' => now()->toIso8601String(), 'started_at' => null, 'checked_at' => null, 'result' => null]);
            $dispatch = true;

            return $requestId;
        });
        if ($dispatch) {
            $fresh = $campaign->fresh()->load('customer');
            $customer = $fresh->customer;
            $paid = $customer->isPaidSetupOnly()
                || $customer->users->contains(fn ($user) => $user->hasSubscriptionAccess($customer));
            $google = $fresh->strategies()->where(fn ($query) => $query->where('platform', 'ilike', '%google%'))->exists();
            if ($google && ! $customer->google_ads_customer_id && ! $customer->is_sandbox && ($prepareAccount || $paid)) {
                // Provisioning precedes research, never approval. Free website
                // previews create no accounts without this explicit intent.
                Bus::chain([(new ProvisionGoogleAdsAccount($customer))->afterCommit(), new ResearchCampaignMarket($fresh, $requestId)])->dispatch();
            } else {
                ResearchCampaignMarket::dispatch($fresh, $requestId)->afterCommit();
            }
        }

        return $this->status($campaign);
    }

    public function run(Campaign $campaign, string $requestId): void
    {
        Cache::lock('campaign-market-research:'.$campaign->id, 660)->block(5, function () use ($campaign, $requestId): void {
            $fingerprint = DB::transaction(function () use ($campaign, $requestId): ?string {
                $current = Campaign::whereKey($campaign->id)->lockForUpdate()->first();
                if (! $current || ($this->stored($current)['request_id'] ?? null) !== $requestId) {
                    return null;
                }
                // Account preparation may have completed since dispatch. Bind
                // this attempt to fresh inputs before its slow provider reads.
                $fingerprint = $this->research->fingerprint($current, $this->stored($current)['requested_daily_budget_micros'] ?? null);
                $this->write($current, [...$this->stored($current), 'input_fingerprint' => $fingerprint,
                    'status' => 'researching', 'started_at' => now()->toIso8601String(), 'result' => null]);

                return $fingerprint;
            });
            if ($fingerprint === null) {
                return;
            }
            $result = $this->research->research($campaign->fresh(), $this->stored($campaign->fresh())['requested_daily_budget_micros'] ?? null);
            DB::transaction(function () use ($campaign, $requestId, $fingerprint, $result): void {
                $current = Campaign::whereKey($campaign->id)->lockForUpdate()->first();
                if (! $current || ($this->stored($current)['request_id'] ?? null) !== $requestId) {
                    return;
                }
                $current->setRelation('strategies', $current->strategies()->orderBy('id')->lockForUpdate()->get());
                if ($this->research->fingerprint($current, $this->stored($current)['requested_daily_budget_micros'] ?? null) !== $fingerprint || ($result['fingerprint'] ?? null) !== $fingerprint) {
                    $this->write($current, [...$this->stored($current), 'status' => 'stale', 'result' => null,
                        'checked_at' => now()->toIso8601String()]);

                    return;
                }
                $this->write($current, [...$this->stored($current), 'status' => ($result['status'] ?? null) === 'ready' ? 'ready' : 'needs_review',
                    'result' => $result, 'checked_at' => now()->toIso8601String(), 'message' => null]);
            });
        });
    }

    public function status(Campaign $campaign): array
    {
        $current = $campaign->fresh();
        if (! $current) {
            return ['status' => 'unknown', 'current' => false, 'approved' => false, 'approvable' => false];
        }
        $stored = $this->stored($current);
        $approved = ($stored['status'] ?? null) === 'ready' && ($stored['result']['status'] ?? null) === 'ready'
            && ($stored['approved_request_id'] ?? null) === ($stored['request_id'] ?? null)
            && ! empty($stored['approved_fingerprint']) && $current->budget_confirmed_at
            && hash_equals($stored['approved_fingerprint'], $this->research->approvalFingerprint($current));
        $matches = ($stored['input_fingerprint'] ?? null) === $this->research->fingerprint($current, $stored['requested_daily_budget_micros'] ?? null);
        $fresh = $this->fresh($stored);
        $status = $stored['status'] ?? 'not_requested';
        if ($status === 'ready' && ! $approved && (! $matches || ! $fresh)) {
            $status = 'stale';
        }
        if (in_array($status, ['queued', 'researching'], true) && ! $this->pendingFresh($stored)) {
            $status = 'stale';
        }

        return [...$stored, 'status' => $status, 'current' => (bool) ($approved || $matches), 'approved' => (bool) $approved,
            'approvable' => $status === 'ready' && $matches && $fresh && ! $approved,
            'expires_at' => isset($stored['checked_at']) ? \Carbon\CarbonImmutable::parse($stored['checked_at'])->addMinutes(15)->toIso8601String() : null];
    }

    public function proposalForApproval(Campaign $campaign, int $dailyCents, ?string $requestId, bool $acceptProposal, ?string $fingerprint = null): array
    {
        $status = $this->status($campaign);
        $result = $status['result'] ?? [];
        if (! $acceptProposal || ! $requestId || ($status['request_id'] ?? null) !== $requestId
            || ! $fingerprint || ! hash_equals($status['input_fingerprint'] ?? '', $fingerprint)
            || ! ($status['approvable'] ?? false) || ($result['status'] ?? null) !== 'ready'
            || ($result['fingerprint'] ?? null) !== ($status['input_fingerprint'] ?? null)) {
            throw ValidationException::withMessages(['daily_budget' => 'Review the current agent research before approving its campaign proposal and daily budget.']);
        }
        if (($result['proposed_daily_budget_micros'] ?? null) !== $dailyCents * 10_000) {
            throw ValidationException::withMessages(['daily_budget' => 'Approve the researched daily budget shown with this proposal. A different amount needs new research.']);
        }

        return $result;
    }

    /** @param Collection<int, Strategy> $strategies */
    public function applyApprovedProposal(Campaign $campaign, Collection $strategies, array $proposal): void
    {
        $rows = collect($proposal['strategies'] ?? [])->keyBy('strategy_id');
        if ($rows->count() !== $strategies->count()) {
            throw ValidationException::withMessages(['daily_budget' => 'The campaign strategy selection changed. Research the current selection before approval.']);
        }
        $search = $strategies->filter(fn ($strategy) => str_contains(strtolower($strategy->platform), 'google') && $strategy->campaign_type === 'search');
        if ($search->count() > 1 && ! empty($campaign->keywords)
            && $search->contains(fn ($strategy) => $this->keywordSelection($campaign->keywords) !== $this->keywordSelection($rows->get($strategy->id)['proposed_keywords'] ?? []))) {
            if ($search->contains(fn ($strategy) => $strategy->hasDeploymentFootprint() || $strategy->reusableGoogleCampaignId() !== null)) {
                throw ValidationException::withMessages(['daily_budget' => 'Shared keywords belonging to an existing platform campaign require a separate live review.']);
            }
            // Explicit acceptance adopts the visible per-strategy selections.
            // Remove the shared override so deployment uses those exact terms.
            $campaign->update(['keywords' => null]);
        }
        $total = 0;
        foreach ($strategies as $strategy) {
            $row = $rows->get($strategy->id);
            $micros = $row['budget_micros'] ?? null;
            if (! is_array($row) || ! is_int($micros) || $micros <= 0 || $micros % 10_000 !== 0) {
                throw ValidationException::withMessages(['daily_budget' => 'Every current strategy needs a complete researched proposal.']);
            }
            $total += $micros;
            if ($strategy->hasDeploymentFootprint() || $strategy->reusableGoogleCampaignId() !== null) {
                $selected = str_contains(strtolower($strategy->platform), 'google') && $strategy->campaign_type === 'search'
                    ? app(SearchKeywordBuilder::class, ['customer' => $campaign->customer])->getKeywords($campaign, $strategy, new ExecutionPlan([])) : ($row['current_keywords'] ?? []);
                if (($row['status'] ?? null) !== 'preserved' || CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget) !== $micros
                    || ($row['cpc_bid_micros'] ?? null) !== null || ($row['forecast'] ?? null) !== null
                    || $this->keywordSelection($row['current_keywords'] ?? []) !== $this->keywordSelection($selected)
                    || $this->keywordSelection($row['proposed_keywords'] ?? []) !== $this->keywordSelection($selected)) {
                    throw ValidationException::withMessages(['daily_budget' => 'Existing platform allocations cannot be changed by accepting a draft proposal.']);
                }

                continue;
            }
            if (($row['status'] ?? null) !== 'ready') {
                throw ValidationException::withMessages(['daily_budget' => 'Every new strategy needs a ready researched proposal.']);
            }
            $bidding = $strategy->bidding_strategy ?? [];
            if (str_contains(strtolower($strategy->platform), 'google') && $strategy->campaign_type === 'search') {
                if (($row['bidding_strategy'] ?? null) !== 'MANUAL_CPC') {
                    throw ValidationException::withMessages(['daily_budget' => 'The researched Search bidding mode must be explicit before approval.']);
                }
                $keywords = $row['proposed_keywords'] ?? [];
                if ($keywords === []) {
                    throw ValidationException::withMessages(['daily_budget' => 'The researched Search keyword selection is missing.']);
                }
                $bidding['keywords'] = $keywords;
                $bidding['name'] = 'ManualCpc';
                $cap = $row['cpc_bid_micros'] ?? null;
                if (is_int($cap) && $cap > 0) {
                    $oldCap = $bidding['parameters']['cpcBidCeilingMicros'] ?? $bidding['cpc_bid_ceiling_micros'] ?? null;
                    if ($oldCap !== null && ((! is_int($oldCap) && (! is_string($oldCap) || ! preg_match('/^\d{1,16}$/', $oldCap))) || $cap > (int) $oldCap)) {
                        throw ValidationException::withMessages(['daily_budget' => 'The proposal cannot raise an existing per-click spending cap.']);
                    }
                    if ($oldCap === null) {
                        $bidding['parameters']['cpcBidCeilingMicros'] = $cap;
                    }
                    $bidding['parameters']['initialCpcBidMicros'] = $cap;
                } else {
                    throw ValidationException::withMessages(['daily_budget' => 'The researched initial Search bid is missing.']);
                }
                if ($search->count() === 1) {
                    $campaign->update(['keywords' => $keywords]);
                }
                if ($targeting = $strategy->targetingConfig()->lockForUpdate()->first()) {
                    $targeting->update(['google_options' => [...($targeting->google_options ?? []), 'keywords' => $keywords]]);
                }
            }
            $cents = intdiv($micros, 10_000);
            $strategy->update(['daily_budget' => intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT), 'bidding_strategy' => $bidding]);
        }
        if ($total !== ($proposal['proposed_daily_budget_micros'] ?? null)
            || $total !== CampaignSpendGuardrails::moneyToMicros($campaign->approved_daily_budget)) {
            throw ValidationException::withMessages(['daily_budget' => 'The researched strategy allocations do not match the approved daily budget.']);
        }
        $this->write($campaign, [...$this->stored($campaign), 'approved_at' => now()->toIso8601String(),
            'approved_request_id' => $this->stored($campaign)['request_id'],
            'approved_fingerprint' => $this->research->approvalFingerprint($campaign->fresh())]);
    }

    public function assertApproved(Campaign $campaign): void
    {
        if (! ($this->status($campaign)['approved'] ?? false)) {
            throw ValidationException::withMessages(['daily_budget' => 'Review and approve the current agent-researched campaign proposal before creating new ads.']);
        }
    }

    public function failed(Campaign $campaign, string $requestId): void
    {
        DB::transaction(function () use ($campaign, $requestId): void {
            $current = Campaign::whereKey($campaign->id)->lockForUpdate()->first();
            if ($current && ($this->stored($current)['request_id'] ?? null) === $requestId) {
                $this->write($current, [...$this->stored($current), 'status' => 'unknown', 'result' => null,
                    'checked_at' => now()->toIso8601String(), 'message' => 'The agent research did not finish. Retry before approving this proposal.']);
            }
        });
    }

    private function stored(Campaign $campaign): array
    {
        return $campaign->search_delivery_state['market_research'] ?? [];
    }

    private function write(Campaign $campaign, array $research): void
    {
        $campaign->update(['search_delivery_state' => [...($campaign->search_delivery_state ?? []), 'market_research' => $research]]);
    }

    private function fresh(array $stored): bool
    {
        try {
            $checked = \Carbon\CarbonImmutable::parse($stored['checked_at'] ?? '1970-01-01');

            return $checked->gte(now()->subMinutes(15)) && $checked->lte(now()->addMinute());
        } catch (\Throwable) {
            return false;
        }
    }

    private function pendingFresh(array $stored): bool
    {
        try {
            $started = \Carbon\CarbonImmutable::parse($stored['started_at'] ?? $stored['requested_at'] ?? '1970-01-01');

            return $started->gte(now()->subMinutes(11)) && $started->lte(now()->addMinute());
        } catch (\Throwable) {
            return false;
        }
    }

    private function keywordSelection(array $keywords): array
    {
        $selection = array_map(function ($keyword): array {
            $limit = is_string($keyword) ? null : ($keyword['cpc_bid_micros'] ?? null);
            if (is_string($limit) && preg_match('/^\d{1,16}$/', $limit)) {
                $limit = (int) $limit;
            }

            return [mb_strtolower(trim(is_string($keyword) ? $keyword : ($keyword['text'] ?? $keyword['keyword'] ?? ''))),
                is_string($keyword) ? 'PHRASE' : strtoupper($keyword['match_type'] ?? 'PHRASE'),
                $limit === 0 ? null : $limit];
        }, $keywords);
        sort($selection);

        return $selection;
    }
}
