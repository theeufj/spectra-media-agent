<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\GoogleAds\CommonServices\ReadGoogleMarketContext;
use App\Services\GoogleAds\InitialSearchBidPlanner;

/** Automatic proposals only. Research never authorizes or changes spend, targeting or platform objects. */
class CampaignMarketResearch
{
    // Unapproved quotes must be rerun when optimizer semantics change. This
    // version deliberately does not enter durable customer approval hashes.
    private const RESEARCH_VERSION = 3;

    public function fingerprint(Campaign $campaign, ?int $requestedDailyBudgetMicros = null): string
    {
        return hash('sha256', json_encode([self::RESEARCH_VERSION, $this->inputFingerprint($campaign, true), $requestedDailyBudgetMicros], JSON_THROW_ON_ERROR));
    }

    public function approvalFingerprint(Campaign $campaign): string
    {
        return $this->inputFingerprint($campaign, false);
    }

    public function research(Campaign $campaign, ?int $requestedDailyBudgetMicros = null): array
    {
        $campaign = $this->current($campaign);
        $fingerprint = $this->fingerprint($campaign, $requestedDailyBudgetMicros);
        $strategies = $campaign->strategies->sortBy('id')->values();
        $hasDrafts = $strategies->contains(fn (Strategy $strategy) => ! $this->hasFootprint($campaign, $strategy));
        $account = [];
        $accountIssue = null;
        if ($campaign->customer?->cleanGoogleCustomerId()) {
            try {
                $account = $this->accountContext($campaign->customer);
            } catch (\Throwable $e) {
                report($e);
                $accountIssue = 'The customer account currency and timezone could not be verified.';
            }
        } else {
            $accountIssue = 'Account-scoped pricing and reach research is waiting for a real customer Google Ads account.';
        }
        $missing = $strategies->filter(fn (Strategy $strategy) => CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget) <= 0);
        $remaining = max(0, CampaignSpendGuardrails::moneyToMicros($campaign->daily_budget)
            - $strategies->sum(fn (Strategy $strategy) => CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget)));
        // A missing draft allocation is a proposal, divided by the same cents
        // primitive as strategy normalization; existing allocations are untouched.
        $shares = $missing->isEmpty() ? [] : CampaignBudgetService::split(intdiv($remaining, 10_000), array_fill_keys($missing->modelKeys(), 1));
        $requestedAllocations = [];
        $requestedIssue = null;
        $only = $strategies->first();
        $existingSearchQuote = $requestedDailyBudgetMicros !== null && $strategies->count() === 1
            && $only instanceof Strategy && $this->isGoogleSearch($only) && $this->hasFootprint($campaign, $only);
        if ($requestedDailyBudgetMicros !== null) {
            $protected = $strategies->filter(fn (Strategy $strategy) => $strategy->hasDeploymentFootprint() || $this->hasFootprint($campaign, $strategy));
            $protectedTotal = $protected->sum(fn (Strategy $strategy) => CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget));
            if ($requestedDailyBudgetMicros < 5_000_000 || $requestedDailyBudgetMicros > 10_000_000_000 || $requestedDailyBudgetMicros % 10_000 !== 0
                || (! $existingSearchQuote && ($protected->contains(fn (Strategy $strategy) => CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget) <= 0)
                    || $requestedDailyBudgetMicros < $protectedTotal))) {
                $requestedIssue = 'The requested daily budget does not fit the supported range and existing platform allocations.';
            } elseif ($existingSearchQuote) {
                // A single existing Search campaign can be modelled at a
                // hypothetical amount. Its real allocation is never rewritten
                // or made approvable by this research-only projection.
                $requestedAllocations['strategy:'.$only->id] = intdiv($requestedDailyBudgetMicros, 10_000);
            } elseif (! $hasDrafts && $strategies->count() > 1 && $requestedDailyBudgetMicros !== $protectedTotal) {
                $requestedIssue = 'A custom total for multiple existing placements needs per-placement allocations. Existing budgets were retained; no combined traffic forecast was produced.';
            } else {
                $drafts = $strategies->reject(fn (Strategy $strategy) => $strategy->hasDeploymentFootprint() || $this->hasFootprint($campaign, $strategy));
                $weights = $drafts->mapWithKeys(fn (Strategy $strategy) => ['strategy:'.$strategy->id => max(1, intdiv(CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget), 10_000))])->all();
                $requestedAllocations = $weights === [] ? [] : CampaignBudgetService::split(intdiv($requestedDailyBudgetMicros - $protectedTotal, 10_000), $weights);
            }
        }
        $results = [];
        foreach ($strategies as $strategy) {
            $budget = CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget) ?: (($shares[$strategy->id] ?? 0) * 10_000);
            if ($requestedDailyBudgetMicros !== null && ($existingSearchQuote || (! $strategy->hasDeploymentFootprint() && ! $this->hasFootprint($campaign, $strategy)))) {
                $budget = ($requestedAllocations['strategy:'.$strategy->id] ?? 0) * 10_000;
            }
            $row = ['strategy_id' => $strategy->id, 'platform' => $strategy->platform,
                'channel' => $strategy->campaign_type, 'status' => 'needs_review',
                'current_budget_micros' => CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget) ?: null,
                'current_cpc_bid_micros' => null, 'budget_micros' => $budget ?: null, 'cpc_bid_micros' => null,
                'current_bidding_strategy' => $strategy->bidding_strategy['name'] ?? null, 'bidding_strategy' => null,
                'current_keywords' => [], 'proposed_keywords' => [], 'forecast' => null,
                'currency_code' => $account['currency_code'] ?? $campaign->customer?->currency_code,
                'time_zone' => $account['time_zone'] ?? null, 'forecast_period' => null,
                'caveats' => [], 'reasons' => [], 'evidence' => ['account' => $account], 'activation_ready' => false, 'auto_repair_safe' => false];
            try {
                $inputIssue = null;
                try {
                    $inputs = $this->inputs($campaign, $strategy);
                } catch (\Throwable $e) {
                    $inputs = ['keywords' => []];
                    $inputIssue = $e->getMessage();
                }
                $selected = $this->keywords($inputs['keywords'] ?? []);
                $row['current_keywords'] = $row['proposed_keywords'] = $selected;
                $context = [...$account, ...array_diff_key($inputs, ['keywords' => true])];
                $business = $this->businessResearch($campaign, $strategy, $context, $selected);
                $row['evidence']['business_research'] = $business;
                $row['evidence']['input_context'] = $context;
                $platformProposal = $hasDrafts && $this->hasFootprint($campaign, $strategy) ? null : $this->platformProposal($campaign, $strategy, $business, $context);
                if ($hasDrafts && $this->hasFootprint($campaign, $strategy) && $row['current_budget_micros'] !== null && ! $requestedIssue) {
                    $row['status'] = 'preserved';
                    $row['budget_micros'] = $row['current_budget_micros'];
                    $row['approval_scope'] = 'unchanged_existing_allocation';
                    $row['reasons'][] = 'The existing allocation and campaign settings are retained. This approval only covers the new draft placements.';
                } elseif ($platformProposal !== null) {
                    $row = array_replace($row, $platformProposal);
                    $row['activation_ready'] = false;
                } elseif (! $this->isGoogleSearch($strategy)) {
                    $row['reasons'][] = 'Pricing and traffic estimates could not be verified for this placement. Review its market plan before approving a budget.';
                    $row['evidence']['coverage'] = 'platform_cost_and_reach_unverified';
                    $row['evidence']['proxy_note'] = 'Google keyword demand is proxy business evidence only, not this placement’s traffic or pricing.';
                } elseif ($accountIssue || $inputIssue) {
                    $row['reasons'][] = $accountIssue ?: $inputIssue;
                } elseif ($requestedIssue) {
                    $row['reasons'][] = $requestedIssue;
                } elseif ($budget <= 0) {
                    $row['reasons'][] = 'A proposed strategy allocation is required before budget and bid combinations can be estimated.';
                } elseif (! empty($inputs['unsupported_targeting'])) {
                    $row['reasons'][] = 'Some planned audience restrictions cannot be reproduced by this forecast. Review targeting before approving a market-backed budget.';
                } else {
                    $row = $this->searchProposal($campaign, $strategy, $context, $selected, $business, $budget, $row, $requestedDailyBudgetMicros === null);
                    if ($this->hasFootprint($campaign, $strategy)) {
                        $row['proposal_status'] = $row['status'];
                        $row['status'] = 'needs_review';
                        $row['requires_live_review'] = true;
                        $row['reasons'][] = 'Existing platform allocations, bids and keywords were preserved. This recommendation needs a separate review before any live campaign changes.';
                    } elseif ($row['status'] === 'ready' && ! empty($campaign->keywords)
                        && $strategies->contains(fn (Strategy $sibling) => $this->isGoogleSearch($sibling) && $this->hasFootprint($campaign, $sibling))
                        && ! $this->sameKeywordSelection($selected, $row['proposed_keywords'])) {
                        $row['proposal_status'] = 'ready';
                        $row['status'] = 'needs_review';
                        $row['requires_shared_keyword_review'] = true;
                        $row['reasons'][] = 'This keyword selection is shared with an existing Search campaign. Review its scope before adopting different keywords for the new placement.';
                    }
                }
            } catch (\Throwable $e) {
                report($e);
                $row['reasons'][] = 'Market research could not be completed. Existing budgets, keywords and platform settings were preserved.';
            }
            $row['caveats'] = array_values(array_unique([...$row['caveats'],
                'Traffic estimates do not guarantee purchases. Purchase conversion rate and revenue remain unverified.',
                'Approval of a proposal does not approve launch; actual campaign settings and conversion tracking require separate verification.']));
            $results[] = $row;
        }
        $total = array_sum(array_column($results, 'budget_micros'));
        $withinRange = $total >= 5_000_000 && $total <= 10_000_000_000;
        $ready = $hasDrafts && $results !== [] && $withinRange && ! array_filter($results, fn ($row) => ! in_array($row['status'], ['ready', 'preserved'], true));

        $result = ['status' => $ready ? 'ready' : 'needs_review', 'fingerprint' => $fingerprint,
            'checked_at' => now()->toIso8601String(), 'strategies' => $results,
            'proposed_daily_budget_micros' => $total,
            'requested_daily_budget_micros' => $requestedDailyBudgetMicros,
            'approval_scope' => $existingSearchQuote ? 'existing_campaign_research_only' : 'unbuilt_strategies',
            'reasons' => $ready ? [] : ($results === [] ? ['Strategy generation must finish before market research can run.']
                : (! $withinRange ? ['The combined proposed daily budget falls outside the supported approval range. Review the allocations; no amount was automatically reduced or approved.']
                    : ['One or more placements require market review before this proposal can be approved.'])),
            'activation_ready' => false];

        return $this->withBudgetOptions($campaign, $result, $strategies, $requestedDailyBudgetMicros);
    }

    private function withBudgetOptions(Campaign $campaign, array $result, \Illuminate\Database\Eloquent\Collection $strategies, ?int $requestedDailyBudgetMicros): array
    {
        $result['budget_options'] = [];
        $result['default_budget_option_id'] = $result['selected_budget_option_id'] = null;
        $strategy = $strategies->first();
        if ($strategies->count() !== 1 || ! $strategy instanceof Strategy || ! $this->isGoogleSearch($strategy)) {
            return $result;
        }
        $row = $result['strategies'][0];
        $budget = $row['budget_micros'];
        $bid = $row['cpc_bid_micros'];
        if (($row['status'] !== 'ready' && ($row['proposal_status'] ?? null) !== 'ready')
            || ! is_int($budget) || ! is_int($bid) || $budget <= 0 || $bid <= 0 || ! is_array($row['forecast'])
            || ($requestedDailyBudgetMicros !== null && $requestedDailyBudgetMicros !== $budget)) {
            return $result;
        }
        $proposal = array_diff_key($result, array_flip(['budget_options', 'default_budget_option_id', 'selected_budget_option_id']));
        $budgets = $requestedDailyBudgetMicros === null
            ? array_values(array_unique([$budget, min(10_000_000_000, $budget * 2), min(10_000_000_000, $budget * 3)])) : [$budget];
        foreach ($budgets as $amount) {
            $optionProposal = $proposal;
            $optionRow = $row;
            if ($amount !== $budget) {
                try {
                    $estimate = $this->bidPlanner()->estimate($campaign, $row['proposed_keywords'], $row['evidence']['input_context'], $amount, $row['cpc_ceiling_micros'] ?? null);
                } catch (\Throwable $e) {
                    report($e);

                    continue; // An unavailable optional quote cannot erase the viable base plan.
                }
                if (($estimate['ready'] ?? false) !== true || ! is_int($estimate['cpc_bid_micros'] ?? null) || $estimate['cpc_bid_micros'] <= 0
                    || $estimate['cpc_bid_micros'] % 10_000 !== 0 || $estimate['cpc_bid_micros'] > $amount
                    || (($row['cpc_ceiling_micros'] ?? null) !== null && $estimate['cpc_bid_micros'] > $row['cpc_ceiling_micros'])
                    || ! is_array($estimate['evidence']['forecast'] ?? null)) {
                    continue;
                }
                $optionRow['budget_micros'] = $amount;
                $optionRow['cpc_bid_micros'] = $estimate['cpc_bid_micros'];
                $optionRow['forecast'] = [...$estimate['evidence']['forecast'], 'auto_repair_safe' => false];
                $optionRow['forecast_period'] = $optionRow['forecast']['forecast_period'] ?? null;
                $optionRow['evidence']['budget_option_forecast'] = $estimate['evidence'];
                $optionRow['caveats'] = array_values(array_unique([...$row['caveats'], ...$estimate['caveats']]));
                $optionRow['reasons'] = ['Fresh traffic estimate for this daily budget using the researched keywords and a bid tested within retained limits.'];
            }
            $optionProposal['proposed_daily_budget_micros'] = $amount;
            $optionProposal['strategies'] = [$optionRow];
            $id = hash('sha256', json_encode($optionProposal, JSON_THROW_ON_ERROR));
            $result['budget_options'][] = ['id' => $id, 'daily_budget_micros' => $amount, 'cpc_bid_micros' => $optionRow['cpc_bid_micros'],
                'currency_code' => $row['currency_code'], 'time_zone' => $row['time_zone'],
                'forecast' => $optionRow['forecast'], 'proposal' => $optionProposal];
        }
        $result['default_budget_option_id'] = $result['selected_budget_option_id'] = $result['budget_options'][0]['id'] ?? null;

        return $result;
    }

    private function searchProposal(Campaign $campaign, Strategy $strategy, array $context, array $selected, array $business, int $budget, array $row, bool $allowHigher): array
    {
        $cap = $strategy->bidding_strategy['parameters']['cpcBidCeilingMicros'] ?? $strategy->bidding_strategy['cpc_bid_ceiling_micros'] ?? null;
        if ($cap !== null && ((! is_int($cap) && ! is_string($cap)) || ! ctype_digit((string) $cap) || (int) $cap <= 0)) {
            $row['reasons'][] = 'The existing CPC ceiling is invalid and cannot be replaced by an automatic proposal.';

            return $row;
        }
        $cap = $cap === null ? null : (int) $cap;
        $keywordCap = null;
        foreach ($selected as $keyword) {
            $limit = $keyword['cpc_bid_micros'] ?? null;
            if ($limit === null || $limit === 0 || $limit === '0') {
                continue;
            }
            if ((! is_int($limit) && (! is_string($limit) || ! preg_match('/^\d{1,16}$/', $limit))) || (int) $limit <= 0) {
                $row['reasons'][] = 'An existing keyword CPC limit is invalid. Review it before replacing or approving the keyword selection.';

                return $row;
            }
            $keywordCap = min($keywordCap ?? (int) $limit, (int) $limit);
        }
        if ($keywordCap !== null) {
            $cap = min($cap ?? $keywordCap, $keywordCap);
        }
        $row['cpc_ceiling_micros'] = $cap;
        $alternatives = $this->keywords($business['keywords'] ?? [], true);
        if ($keywordCap !== null) {
            // An alternative selection cannot discard a lower reviewed keyword
            // limit just because the replacement terms have different names.
            $alternatives = array_map(fn ($keyword) => array_replace($keyword, ['cpc_bid_micros' => $keywordCap]), $alternatives);
        }
        $sets = [$selected];
        $setKinds = ['selected'];
        if ($alternatives !== [] && $alternatives !== $selected) {
            $sets[] = $alternatives;
            $setKinds[] = 'combined';
        }
        $profileKinds = [];
        foreach (array_slice($business['keyword_sets'] ?? [], 0, 3) as $profile) {
            $kind = $profile['kind'] ?? null;
            if (! in_array($kind, ['category', 'specific', 'cost_efficient'], true) || in_array($kind, $profileKinds, true)) {
                continue;
            }
            $profileKinds[] = $kind;
            $keywords = $this->keywords($profile['keywords'] ?? [], true);
            if ($keywordCap !== null) {
                $keywords = array_map(fn ($keyword) => array_replace($keyword, ['cpc_bid_micros' => $keywordCap]), $keywords);
            }
            if ($keywords !== []) {
                $sets[] = $keywords;
                $setKinds[] = $kind;
            }
        }
        $planner = $this->bidPlanner();
        $testedSets = $candidates = $higher = [];
        // Compare every relevant set at the existing proposed spend before
        // recommending a larger budget for the original terms.
        foreach ($sets as $set => $keywords) {
            if ($keywords === []) {
                continue;
            }
            $estimate = $planner->estimate($campaign, $keywords, $context, $budget, $cap);
            $testedSets[$set] = ['kind' => $setKinds[$set], 'keywords' => $keywords, 'budgets' => [['budget_micros' => $budget, 'result' => $estimate]]];
            if (($estimate['ready'] ?? false) === true) {
                $candidates[] = ['set' => $set, 'budget' => $budget, 'estimate' => $estimate];
            }
            foreach ($allowHigher ? $this->budgetCandidates($estimate, $budget) : [] as $higherBudget) {
                $higher[] = ['set' => $set, 'budget' => $higherBudget];
            }
        }
        // Recover ordinary buying vocabulary before recommending more spend,
        // even if discovery produced several niche terms. Every set competes
        // at the current budget first; higher-budget fallbacks remain intact.
        if ($candidates === [] && ! array_key_exists('keyword_sets', $business) && ($business['discovery']['category_recovery_attempted'] ?? null) === false) {
            try {
                $recovery = $this->businessResearchRecovery($campaign, $strategy, $context, $selected);
            } catch (\Throwable $e) {
                report($e);
                $recovery = ['keywords' => [], 'status' => 'unavailable',
                    'reason' => 'Additional category research was unavailable; the original tested choices were retained.'];
            }
            $row['evidence']['business_recovery'] = $recovery;
            $recovered = $this->keywords($recovery['keywords'] ?? [], true);
            if ($keywordCap !== null) {
                $recovered = array_map(fn ($keyword) => array_replace($keyword, ['cpc_bid_micros' => $keywordCap]), $recovered);
            }
            if ($recovered !== [] && ! in_array($recovered, $sets, true)) {
                $sets[] = $recovered;
                $setKinds[] = 'category_recovery';
                $set = array_key_last($sets);
                $estimate = $planner->estimate($campaign, $recovered, $context, $budget, $cap);
                $testedSets[$set] = ['kind' => $setKinds[$set], 'keywords' => $recovered, 'budgets' => [['budget_micros' => $budget, 'result' => $estimate]]];
                if (($estimate['ready'] ?? false) === true) {
                    $candidates[] = ['set' => $set, 'budget' => $budget, 'estimate' => $estimate];
                }
                foreach ($allowHigher ? $this->budgetCandidates($estimate, $budget) : [] as $higherBudget) {
                    $higher[] = ['set' => $set, 'budget' => $higherBudget];
                }
            }
        }
        usort($higher, fn ($a, $b) => [$a['budget'], $a['set']] <=> [$b['budget'], $b['set']]);
        foreach ($higher as $candidate) {
            if ($candidates !== [] && $candidate['budget'] > min(array_column($candidates, 'budget'))) {
                break;
            }
            $estimate = $planner->estimate($campaign, $sets[$candidate['set']], $context, $candidate['budget'], $cap);
            $testedSets[$candidate['set']]['budgets'][] = ['budget_micros' => $candidate['budget'], 'result' => $estimate];
            if (($estimate['ready'] ?? false) === true) {
                $candidates[] = $candidate + ['estimate' => $estimate];
            }
        }
        $row['evidence']['tested_sets'] = array_values($testedSets);
        if ($candidates !== []) {
            $minimumBudget = min(array_column($candidates, 'budget'));
            $eligible = array_map(fn ($candidate) => [...$candidate,
                'forecast' => $candidate['estimate']['evidence']['forecast'], 'cpc_bid_micros' => $candidate['estimate']['cpc_bid_micros']],
                array_values(array_filter($candidates, fn ($candidate) => $candidate['budget'] === $minimumBudget)));
            $chosen = InitialSearchBidPlanner::bestForecast($eligible);
            $estimate = $chosen['estimate'];
            $row['status'] = 'ready';
            $row['proposed_keywords'] = $sets[$chosen['set']];
            $row['budget_micros'] = $chosen['budget'];
            $row['cpc_bid_micros'] = $estimate['cpc_bid_micros'];
            $row['evidence']['selection_policy'] = 'cost_efficient_reach';
            $row['bidding_strategy'] = 'MANUAL_CPC';
            $row['forecast'] = $estimate['evidence']['forecast'];
            $row['forecast']['auto_repair_safe'] = false;
            $row['forecast_period'] = $row['forecast']['forecast_period'] ?? null;
            $row['caveats'] = [...$estimate['caveats'],
                'Google’s forecast does not model negative keywords. Retained or subsequently added negatives may reduce delivery; this proposal cannot establish their effect.'];
            $row['reasons'] = ['This plan favours lower estimated cost per click among relevant keywords that meet the traffic threshold. Purchase profitability still needs conversion evidence.'];

            return $row;
        }
        $row['reasons'][] = 'No tested relevant keyword, bid and budget combination demonstrated useful forecast traffic. Review the offer, target market or retained CPC limit; increasing spend alone may not help.';

        return $row;
    }

    private function budgetCandidates(array $estimate, int $current): array
    {
        $costs = [];
        foreach ($estimate['evidence']['historical']['keywords'] ?? [] as $metric) {
            foreach (['low_top_of_page_bid_micros', 'high_top_of_page_bid_micros'] as $field) {
                if (is_int($metric[$field] ?? null) && $metric[$field] > 0) {
                    // Cost-derived proposal, not a generic platform minimum.
                    if ($metric[$field] > 10_000_000_000) {
                        continue;
                    }
                    $target = max(1, (int) config('optimization.search_delivery.min_forecast_clicks', 30));
                    $dailyCost = intdiv($metric[$field] * $target + 29, 30);
                    $amount = intdiv(max($metric[$field], $dailyCost) + 9_999, 10_000) * 10_000;
                    if ($amount > $current && $amount <= 10_000_000_000) {
                        $costs[] = $amount;
                    }
                }
            }
        }
        $costs = array_values(array_unique($costs));
        sort($costs, SORT_NUMERIC);

        return count($costs) <= 2 ? $costs : [$costs[0], $costs[count($costs) - 1]];
    }

    protected function inputs(Campaign $campaign, Strategy $strategy): array
    {
        return (new SearchMarketInputs($campaign->customer))->forStrategy($campaign, $strategy);
    }

    protected function accountContext(Customer $customer): array
    {
        return (app(ReadGoogleMarketContext::class, ['customer' => $customer]))();
    }

    protected function businessResearch(Campaign $campaign, Strategy $strategy, array $context, array $selected): array
    {
        return app(CampaignKeywordResearch::class, ['customer' => $campaign->customer])->discover($campaign, $strategy, $context, $selected);
    }

    protected function businessResearchRecovery(Campaign $campaign, Strategy $strategy, array $context, array $selected): array
    {
        return app(CampaignKeywordResearch::class, ['customer' => $campaign->customer])->discover($campaign, $strategy, $context, $selected, true);
    }

    protected function bidPlanner(): InitialSearchBidPlanner
    {
        return app(InitialSearchBidPlanner::class);
    }

    protected function platformProposal(Campaign $campaign, Strategy $strategy, array $business, array $context): ?array
    {
        foreach (app()->tagged('campaign.market_research.providers') as $provider) {
            if ($provider instanceof CampaignMarketResearchProvider && $provider->supports($strategy)) {
                return $provider->propose($campaign, $strategy, $business, $context);
            }
        }

        return null;
    }

    private function isGoogleSearch(Strategy $strategy): bool
    {
        return (bool) preg_match('/google|adwords/i', $strategy->platform)
            && strtolower((string) $strategy->campaign_type) === 'search';
    }

    private function sameKeywordSelection(array $left, array $right): bool
    {
        $keys = function (array $keywords): array {
            $result = array_map(fn ($keyword) => mb_strtolower(trim($keyword['text'])).'|'.$keyword['match_type'].'|'.(int) ($keyword['cpc_bid_micros'] ?? 0), $keywords);
            sort($result, SORT_STRING);

            return $result;
        };

        return $keys($left) === $keys($right);
    }

    private function hasFootprint(Campaign $campaign, Strategy $strategy): bool
    {
        $strategy->setRelation('campaign', $campaign);

        return ! $strategy->requiresInitialMarketResearch();
    }

    private function keywords(array $keywords, bool $alternatives = false): array
    {
        $result = [];
        foreach ($keywords as $keyword) {
            if (! is_array($keyword) || ! is_string($keyword['text'] ?? null) || trim($keyword['text']) === ''
                || ! in_array($keyword['match_type'] ?? null, $alternatives ? ['EXACT', 'PHRASE'] : ['EXACT', 'PHRASE', 'BROAD'], true)
                || $alternatives && (! is_string($keyword['selection_reason'] ?? null) || trim($keyword['selection_reason']) === '')) {
                continue;
            }
            $row = ['text' => trim($keyword['text']), 'match_type' => $keyword['match_type']];
            if (is_string($keyword['selection_reason'] ?? null)) {
                $row['selection_reason'] = $keyword['selection_reason'];
            }
            if (array_key_exists('cpc_bid_micros', $keyword)) {
                // Keep the reviewed limit, including an invalid value requiring
                // review, instead of silently dropping a spending constraint.
                $row['cpc_bid_micros'] = $keyword['cpc_bid_micros'];
            }
            $result[] = $row;
        }

        return $result;
    }

    private function current(Campaign $campaign): Campaign
    {
        return $campaign->exists ? ($campaign->fresh(['customer.brandGuideline', 'strategies.targetingConfig']) ?? throw new \RuntimeException('The campaign no longer exists.')) : $campaign;
    }

    private function inputFingerprint(Campaign $campaign, bool $operational): string
    {
        $campaign = $this->current($campaign);
        $customer = $campaign->customer;
        $fields = ['name', 'daily_budget', 'approved_daily_budget', 'start_date', 'end_date', 'keywords', 'geographic_targeting',
            'goals', 'target_market', 'product_focus', 'landing_page_url', 'exclusions', 'platforms'];
        $strategies = [];
        foreach ($campaign->strategies->sortBy('id') as $strategy) {
            $row = $strategy->only(['id', 'uuid', 'generation_id', 'platform', 'campaign_type', 'daily_budget', 'bidding_strategy', 'conversion_goals']);
            $row['targeting'] = $strategy->targetingConfig?->getAttributes();
            if ($operational) {
                $row['lifecycle'] = $strategy->only(['signed_off_at', 'deployed_at', 'deployment_status', 'google_ads_campaign_id',
                    'google_ads_ad_group_id', 'facebook_campaign_id', 'microsoft_campaign_id', 'linkedin_campaign_id']);
                $row['platform_ids'] = $strategy->execution_result['platform_ids'] ?? [];
            }
            $strategies[] = $row;
        }
        $inputs = [$campaign->id, $campaign->customer_id, $campaign->only($fields),
            $customer?->only(['id', 'name', 'description', 'website', 'business_type', 'country', 'currency_code', 'timezone',
                'service_type', 'google_ads_customer_id', 'google_ads_link_status', 'facebook_ads_account_id',
                'microsoft_ads_customer_id', 'microsoft_ads_account_id', 'linkedin_ads_account_id']),
            $customer?->brandGuideline?->getAttributes(), $strategies];
        if ($operational) {
            $inputs[] = $campaign->only(['status', 'spend_safety_hold', 'spend_guardrails', 'google_ads_campaign_id',
                'facebook_ads_campaign_id', 'microsoft_ads_campaign_id', 'linkedin_ads_campaign_id']);
        }
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            $value = array_map($sort, $value);
            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($inputs), JSON_THROW_ON_ERROR));
    }
}
