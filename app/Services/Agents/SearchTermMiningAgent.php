<?php

namespace App\Services\Agents;

use App\Contracts\Ads\AdsServiceFactory;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Keyword;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SearchTermMiningAgent
{
    protected array $config;

    /**
     * Declared rather than promoted, with a default, so partial mocks that
     * bypass the constructor leave it null instead of uninitialised.
     */
    private ?AdsServiceFactory $ads = null;

    private ?array $googleKeywordProposals = null;

    private bool $googleKeywordBatchApproved = false;

    public function __construct(?AdsServiceFactory $ads = null)
    {
        $this->ads = $ads;

        // Merge legacy budget_rules config with new optimization config (optimization takes precedence)
        $this->config = array_merge(
            config('budget_rules.search_term_mining', []),
            config('optimization.search_terms', [])
        );
    }

    /**
     * Mine search terms for a campaign and make keyword changes.
     *
     * @return array Results of mining actions
     */
    /**
     * Platform services for this run — synthetic for sandbox customers.
     */
    private function ads(): AdsServiceFactory
    {
        return $this->ads ??= app(AdsServiceFactory::class);
    }

    public function mine(Campaign $campaign, bool $googleOnly = false): array
    {
        $results = [
            'campaign_id' => $campaign->id,
            'keywords_added' => [],
            'negatives_added' => [],
            'terms_analyzed' => 0,
            'errors' => [],
        ];

        if (CampaignSpendGuardrails::automaticChangesSuspended($campaign)) {
            return [...$results, 'skipped' => true, 'reason' => 'Automatic keyword changes suspended by trial or spending hold.'];
        }

        if (! $campaign->customer) {
            return $results;
        }

        if ($campaign->google_ads_campaign_id) {
            $this->mineGoogle($campaign, $results);
        }

        if (! $googleOnly && $campaign->microsoft_ads_campaign_id) {
            $this->mineMicrosoft($campaign, $results);
        }

        return $results;
    }

    /**
     * Mine search terms for Google Ads
     */
    protected function mineGoogle(Campaign $campaign, array &$results): void
    {
        $customer = $campaign->customer;
        $customerId = $customer->cleanGoogleCustomerId();
        $campaignResourceName = $campaign->googleAdsResourceName();

        try {
            $getSearchTerms = $this->ads()->searchTerms($customer);
            $searchTerms = $getSearchTerms($customerId, $campaignResourceName, 'LAST_30_DAYS_INCLUDING_TODAY');

            $results['terms_analyzed'] += count($searchTerms);
            $this->googleKeywordProposals = [];
            $protected = [];
            foreach ($searchTerms as $term) {
                $key = mb_strtolower(trim($term['search_term']));
                $protected[$key] = ($protected[$key] ?? 0) + max((float) $term['conversions'], (float) ($term['all_conversions'] ?? 0));
            }
            $maturedByTerm = [];
            if ($searchTerms !== []) {
                $maturedTerms = $customer->is_sandbox ? $searchTerms
                    : $getSearchTerms($customerId, $campaignResourceName, 'MATURED_LAST_30_DAYS');
                foreach ($maturedTerms as $term) {
                    if (! $customer->is_sandbox && ! $this->maturedGoogleTerm($term)) {
                        continue;
                    }
                    if (! array_key_exists(mb_strtolower(trim($term['search_term'])), $protected)) {
                        // A missing fresh row cannot prove an absence of new
                        // conversions for an older reported search term.
                        continue;
                    }
                    $maturedByTerm[mb_strtolower(trim($term['search_term'])).'|'.($term['ad_group_resource_name'] ?? '')] = $term;
                }
            }
            foreach ($searchTerms as $term) {
                // Recent aggregate spend cannot establish that a click has had
                // time to convert. This pass may only promote proven terms.
                $term['matured'] = false;
                $this->evaluateSearchTerm($customer, $customerId, $campaignResourceName, $term, $results, 'google', $campaign);
                $text = mb_strtolower(trim($term['search_term']));
                $matured = $maturedByTerm[$text.'|'.($term['ad_group_resource_name'] ?? '')] ?? null;
                if ($matured !== null) {
                    $matured['matured'] = true;
                    $matured['exclusions_only'] = true;
                    $matured['conversions'] = max((float) $matured['conversions'], $protected[$text]);
                    $this->evaluateSearchTerm($customer, $customerId, $campaignResourceName, $matured, $results, 'google', $campaign);
                }
            }
            $proposals = $this->googleKeywordProposals;
            $this->googleKeywordProposals = null;
            $this->applyGoogleKeywordProposals($campaign, $proposals, $results);

        } catch (\Throwable $e) {
            report($e);
            $results['errors'][] = 'Failed to get google search terms: '.$e->getMessage();
            Log::error('SearchTermMiningAgent: Failed to mine google search terms', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->googleKeywordProposals = null;
            $this->googleKeywordBatchApproved = false;
        }
    }

    private function applyGoogleKeywordProposals(Campaign $campaign, array $proposals, array &$results): void
    {
        if ($proposals === []) {
            return;
        }
        // Sandbox mutators only record synthetic decisions and cannot reach Google.
        try {
            if (! $campaign->customer->is_sandbox && array_filter($proposals, fn ($proposal) => $proposal['action'] === 'add') !== []) {
                $keywords = $this->existingGoogleKeywords($campaign);
                $proposals = array_filter($proposals, function ($proposal) use ($keywords): bool {
                    if ($proposal['action'] !== 'add') {
                        return true;
                    }
                    foreach ($keywords as $keyword) {
                        if (($keyword['status'] ?? null) !== 'REMOVED'
                            && ($keyword['ad_group_resource'] ?? null) === $proposal['ad_group_resource']
                            && ($keyword['match_type'] ?? null) === $proposal['match_type']
                            && mb_strtolower(trim($keyword['text'] ?? '')) === mb_strtolower(trim($proposal['text']))) {
                            return false;
                        }
                    }

                    return true;
                });
            }
            if ($proposals === []) {
                return;
            }
            $guard = app(GoogleSearchMutationGuard::class);
            $costOnly = array_filter($proposals, fn ($proposal) => ($proposal['action'] ?? null) !== 'negative'
                || ($proposal['match_type'] ?? null) !== 'EXACT' || ($proposal['scope'] ?? null) !== 'campaign') === [];
            $assessment = $campaign->customer->is_sandbox ? ['ready' => true]
                : ($costOnly ? $guard->assessCostExclusions($campaign, array_values($proposals))
                    : $guard->assess($campaign, array_values($proposals)));
        } catch (\Throwable $e) {
            report($e);
            $assessment = ['ready' => false, 'message' => 'Keyword demand and affordable traffic could not be verified.'];
            $results['errors'][] = 'Keyword portfolio assessment unavailable: '.$e->getMessage();
        }
        if (! ($assessment['ready'] ?? false)) {
            $reason = $assessment['message'] ?? 'Keyword demand and affordable traffic could not be verified at the approved settings.';
            $results['keywords_deferred'] = $proposals;
            $results['requires_review'] = true;
            $results['review_reason'] = $reason;
            AgentActivity::record('search_term_mining', 'keyword_changes_need_review', $reason,
                $campaign->customer_id, $campaign->id, ['proposals' => $proposals, 'assessment' => $assessment]);

            return;
        }
        if (! $campaign->customer->is_sandbox) {
            // A lookback report still contains searches excluded on an earlier
            // run. Reuse the guard's fresh, verified live inventory so a repeat
            // does not send duplicate exclusions and manufacture a failure.
            $existingNegatives = array_values(array_filter($assessment['observed_snapshot']['negative_keywords'] ?? [],
                fn ($negative) => ($negative['scope'] ?? null) === 'campaign'));
            $proposals = array_values(array_filter($proposals, fn ($proposal) => $proposal['action'] !== 'negative'
                || ! GoogleSearchReachPlanner::negativeConflict($proposal['text'], $existingNegatives, '')));
            if ($proposals === []) {
                return;
            }
        }
        $this->googleKeywordBatchApproved = true;
        // Exclusions depend on the replacement portfolio. Install additions first,
        // then verify Google's actual portfolio before removing any reach.
        $proposals = [...array_filter($proposals, fn ($proposal) => $proposal['action'] === 'add'),
            ...array_filter($proposals, fn ($proposal) => $proposal['action'] === 'negative')];
        $hasAdditions = false;
        $negativesVerified = false;
        try {
            foreach ($proposals as $proposal) {
                if (CampaignSpendGuardrails::automaticChangesSuspended($campaign)) {
                    if ($results['keywords_added'] !== [] || $results['negatives_added'] !== []) {
                        $this->recordPartialGoogleBatch($campaign, $proposals, $proposal, $results,
                            ['ready' => false, 'message' => 'A new trial or spending hold prevents remaining changes and portfolio verification.']);

                        break;
                    }
                    $results['requires_review'] = true;
                    $results['review_reason'] = 'A trial or spending hold started before the keyword change.';
                    AgentActivity::record('search_term_mining', 'keyword_changes_need_review', $results['review_reason'],
                        $campaign->customer_id, $campaign->id, ['proposals' => $proposals]);

                    break;
                }
                if ($proposal['action'] === 'negative') {
                    if ($hasAdditions && ! $negativesVerified && ! $campaign->customer->is_sandbox) {
                        try {
                            $assessment = app(GoogleSearchMutationGuard::class)->assess($campaign,
                                array_values(array_filter($proposals, fn ($change) => $change['action'] === 'negative')));
                        } catch (\Throwable $e) {
                            report($e);
                            $assessment = ['ready' => false, 'message' => 'The installed keyword portfolio could not be verified before exclusions.'];
                        }
                        if (! ($assessment['ready'] ?? false)) {
                            $this->recordPartialGoogleBatch($campaign, $proposals, $proposal, $results, $assessment);

                            break;
                        }
                        $negativesVerified = true;
                    }
                    $before = count($results['negatives_added']);
                    $this->addAsNegative($campaign->customer, $campaign->customer->cleanGoogleCustomerId(),
                        $campaign->googleAdsResourceName(), $proposal['text'], $proposal['reason'], $results, $proposal['match_type']);
                    $succeeded = count($results['negatives_added']) === $before + 1;
                } else {
                    $hasAdditions = true;
                    $before = count($results['keywords_added']);
                    $this->addAsKeyword($campaign->customer, $campaign->customer->cleanGoogleCustomerId(),
                        $proposal['ad_group_resource'], $proposal['text'], KeywordMatchType::value($proposal['match_type']), $results);
                    $succeeded = count($results['keywords_added']) === $before + 1;
                }
                if (! $succeeded && ! $campaign->customer->is_sandbox) {
                    try {
                        $readback = app(GoogleSearchMutationGuard::class)->assess($campaign, []);
                    } catch (\Throwable $e) {
                        report($e);
                        $readback = ['ready' => false, 'message' => 'The actual keyword portfolio could not be verified after an incomplete change.'];
                    }
                    $this->recordPartialGoogleBatch($campaign, $proposals, $proposal, $results, $readback);

                    break;
                }
            }
        } finally {
            $this->googleKeywordBatchApproved = false;
        }
    }

    protected function existingGoogleKeywords(Campaign $campaign): array
    {
        $snapshot = app(InspectSearchDelivery::class, ['customer' => $campaign->customer])->inspect($campaign);
        if (! is_array($snapshot) || ! is_array($snapshot['keywords'] ?? null)) {
            throw new \RuntimeException('Existing Google keywords could not be verified before proposing additions.');
        }
        $customerId = $campaign->customer->cleanGoogleCustomerId();

        return array_values(array_filter($snapshot['keywords'], function ($keyword) use ($customerId): bool {
            if (! is_array($keyword) || ! preg_match('~^customers/'.preg_quote($customerId, '~').'/adGroups/(\d+)$~',
                $keyword['ad_group_resource'] ?? '', $group)) {
                return false;
            }

            return (bool) preg_match('~^customers/'.preg_quote($customerId, '~').'/adGroupCriteria/'.$group[1].'\~\d+$~',
                $keyword['resource_name'] ?? '');
        }));
    }

    private function recordPartialGoogleBatch(Campaign $campaign, array $proposals, array $failed, array &$results, array $readback): void
    {
        $reason = 'The keyword batch was not fully installed or its resulting reach could not be verified. Remaining changes are held for review.';
        $results['requires_review'] = true;
        $results['review_reason'] = $reason;
        $details = ['planned_changes' => $proposals, 'failed_change' => $failed,
            'applied_keywords' => $results['keywords_added'], 'applied_negatives' => $results['negatives_added'],
            'actual_portfolio_assessment' => $readback];
        DB::transaction(function () use ($campaign, $reason, $details): void {
            $current = Campaign::whereKey($campaign->id)->where('customer_id', $campaign->customer_id)->lockForUpdate()->first();
            if (! $current) {
                return;
            }
            $state = $current->search_delivery_state ?? [];
            $state['status'] = 'needs_review';
            $state['mutation_allowed'] = false;
            $state['verification']['errors'][] = ['code' => 'keyword_batch_partial_apply', 'message' => $reason,
                'agent' => 'search_term_mining', 'checked_at' => now()->toIso8601String(), ...$details];
            $current->forceFill(['search_delivery_state' => $state])->save();
        });
        AgentActivity::record('search_term_mining', 'keyword_changes_need_review', $reason,
            $campaign->customer_id, $campaign->id, $details, 'needs_review');
    }

    /**
     * Mine search terms for Microsoft Ads
     */
    protected function mineMicrosoft(Campaign $campaign, array &$results): void
    {
        $customer = $campaign->customer;
        if (! $customer->microsoft_ads_account_id) {
            return;
        }

        try {
            $perfService = new \App\Services\MicrosoftAds\PerformanceService($customer);
            $searchTerms = $perfService->getSearchTerms($campaign->microsoft_ads_campaign_id);

            $results['terms_analyzed'] += count($searchTerms);

            foreach ($searchTerms as $term) {
                // Map Microsoft structure if needed, or assume it matches Google's exactly via getSearchTerms
                $this->evaluateSearchTerm($customer, $customer->microsoft_ads_account_id, $campaign->microsoft_ads_campaign_id, $term, $results, 'microsoft');
            }

        } catch (\Throwable $e) {
            report($e);
            $results['errors'][] = 'Failed to get microsoft search terms: '.$e->getMessage();
            Log::error('SearchTermMiningAgent: Failed to mine microsoft search terms', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Evaluate a single search term and take action if needed.
     */
    protected function evaluateSearchTerm(
        Customer $customer,
        string $customerId,
        string $campaignResourceName,
        array $term,
        array &$results,
        string $platform = 'google',
        ?Campaign $campaign = null
    ): void {
        $minImpressions = $this->config['min_impressions'] ?? 300;
        $minClicks = $this->config['min_clicks'] ?? 5;
        $promoteCtrThreshold = $this->config['promote_ctr_threshold'] ?? 0.05;
        $negativeCostThreshold = $this->config['negative_cost_threshold'] ?? 50.00;
        $negativeCtrThreshold = $this->config['negative_ctr_threshold'] ?? 0.002;
        $negativeMinImpressions = $this->config['negative_min_impressions'] ?? 500;

        $searchTerm = $term['search_term'];
        $impressions = $term['impressions'];
        $clicks = $term['clicks'];
        $cost = $term['cost'];
        $conversions = $term['conversions'];
        $ctr = $term['ctr'];

        // A few expensive clicks can consume a small campaign's test budget
        // long before 300 impressions. Only matured spend with no conversion
        // evidence may propose an exclusion; the portfolio guard still owns
        // authorization and affordable remaining reach.
        if ($platform === 'google' && ($term['matured'] ?? false) === true && $conversions <= 0
            && (float) ($term['all_conversions'] ?? 0) <= 0
            && $clicks >= max(2, (int) ($this->config['negative_min_clicks'] ?? 2))
            && $this->termCostMicros($term) >= $this->negativeCostMicros($campaign)) {
            $this->addAsNegative($customer, $customerId, $campaignResourceName, $searchTerm,
                'Matured spend exceeded the campaign-relative threshold without recorded conversions', $results, 'EXACT');

            return;
        }

        // Skip if not enough data
        if ($impressions < $minImpressions) {
            return;
        }

        // Check if this is a HIGH PERFORMER → Add as keyword with match type based on conversion volume
        if (! ($term['exclusions_only'] ?? false) && $clicks >= $minClicks && $ctr >= $promoteCtrThreshold && $conversions > 0) {
            $matchType = $this->determineMatchType($conversions, $platform);
            if ($platform === 'google') {
                $this->addAsKeyword($customer, $customerId, $term['ad_group_resource_name'], $searchTerm, $matchType, $results);
            } else {
                $this->addAsMicrosoftKeyword($customer, $term['ad_group_resource_name'], $searchTerm, $matchType, $results);
            }

            return;
        }

        // Check if this is WASTING MONEY → Add as negative
        // Safety: never auto-negative a term that has converted, regardless of other signals
        if ($platform !== 'google' && $cost >= $negativeCostThreshold && $conversions == 0) {
            $this->addAsMicrosoftNegative($customer, $campaignResourceName, $searchTerm, 'High cost, no conversions', $results);

            return;
        }

        // Low CTR alone is not wasted spend. Keep a review candidate rather
        // than letting a zero-cost Google query block proven cost exclusions.
        if ($impressions >= $negativeMinImpressions && $ctr < $negativeCtrThreshold && $conversions == 0
            && ($platform !== 'google' || ($term['matured'] ?? false) === true && (float) ($term['all_conversions'] ?? 0) <= 0)) {
            if ($platform === 'google') {
                $results['review_candidates'][] = ['search_term' => $searchTerm, 'reason' => 'Low CTR alone does not prove wasted spend.',
                    'impressions' => $impressions, 'clicks' => $clicks, 'cost_micros' => $this->termCostMicros($term)];
            } else {
                $this->addAsMicrosoftNegative($customer, $campaignResourceName, $searchTerm, 'Low CTR, no conversions', $results);
            }

            return;
        }
    }

    private function termCostMicros(array $term): int
    {
        $micros = $term['cost_micros'] ?? null;
        if (is_int($micros) || is_string($micros) && ctype_digit($micros)) {
            return max(0, (int) $micros);
        }

        return CampaignSpendGuardrails::moneyToMicros($term['cost'] ?? 0);
    }

    private function negativeCostMicros(?Campaign $campaign): int
    {
        $fallback = CampaignSpendGuardrails::moneyToMicros($this->config['negative_cost_threshold'] ?? 50);
        if ($fallback <= 0) {
            return PHP_INT_MAX;
        }
        if (! $campaign?->budget_confirmed_at) {
            return $fallback;
        }
        $approved = CampaignSpendGuardrails::moneyToMicros($campaign->approved_daily_budget);
        if ($approved <= 0) {
            return $fallback;
        }
        $limits = $campaign->spend_guardrails['automation_limits'] ?? [];
        if (($limits['enabled'] ?? false) === true && is_int($limits['max_daily_budget_micros'] ?? null)
            && $limits['max_daily_budget_micros'] > 0) {
            $approved = min($approved, $limits['max_daily_budget_micros']);
        }
        $fraction = $this->config['negative_daily_budget_fraction'] ?? 0.5;
        if (! is_numeric($fraction) || ! is_finite((float) $fraction) || $fraction <= 0 || $fraction > 1) {
            return $fallback;
        }
        $basisPoints = (int) floor((float) $fraction * 10_000);

        $relative = intdiv($approved, 10_000) * $basisPoints + intdiv(($approved % 10_000) * $basisPoints, 10_000);

        return max(1, min($fallback, $relative));
    }

    private function maturedGoogleTerm(array $term): bool
    {
        $timezone = $term['account_timezone'] ?? null;
        $through = $term['matured_through'] ?? null;
        if (($term['matured'] ?? false) !== true || ! is_string($timezone)
            || ! in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)
            || ! is_string($through)) {
            return false;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $through, $timezone);
            $latest = CarbonImmutable::now($timezone)->startOfDay()
                ->subDays(max(1, (int) ($this->config['conversion_lag_days'] ?? 2)) + 1);

            return $date->toDateString() === $through && $date->lte($latest);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Determine match type based on conversion volume.
     * High-confidence terms (10+ conversions) → EXACT
     * Medium-confidence (5-9 conversions) → PHRASE
     * Low-confidence (1-4 conversions) → EXACT, preserving the observed query
     */
    protected function determineMatchType(float $conversions, string $platform = 'google'): int|string
    {
        if ($platform === 'google') {
            if ($conversions >= 10) {
                return KeywordMatchType::EXACT;
            }
            if ($conversions >= 5) {
                return KeywordMatchType::PHRASE;
            }

            return KeywordMatchType::EXACT;
        } else {
            // Microsoft uses strings instead of enums
            if ($conversions >= 10) {
                return 'Exact';
            }
            if ($conversions >= 5) {
                return 'Phrase';
            }

            return 'Broad';
        }
    }

    /**
     * Add a search term as a keyword with the specified match type.
     */
    protected function addAsKeyword(Customer $customer, string $customerId, string $adGroupResourceName, string $keyword, int $matchType, array &$results): void
    {
        if ($this->googleKeywordProposals !== null) {
            $key = $adGroupResourceName.'|'.mb_strtolower($keyword).'|'.$matchType;
            $this->googleKeywordProposals[$key] = ['action' => 'add', 'text' => $keyword,
                'match_type' => KeywordMatchType::name($matchType), 'ad_group_resource' => $adGroupResourceName,
                'selection_reason' => 'Observed search term with sufficient clicks, strong click-through rate and recorded conversions for this campaign.'];

            return;
        }
        if (! $this->googleKeywordBatchApproved) {
            $results['requires_review'] = true;
            $results['review_reason'] = 'Google keyword additions require a verified campaign portfolio assessment.';

            return;
        }
        try {
            $resourceName = $this->ads()->keywords($customer)
                ->addKeyword($customerId, $adGroupResourceName, $keyword, $matchType);

            $matchTypeName = match ($matchType) {
                KeywordMatchType::EXACT => 'EXACT',
                KeywordMatchType::PHRASE => 'PHRASE',
                KeywordMatchType::BROAD => 'BROAD',
                default => 'UNKNOWN',
            };

            if ($resourceName) {
                $results['keywords_added'][] = [
                    'keyword' => $keyword,
                    'match_type' => $matchTypeName,
                    'resource_name' => $resourceName,
                ];

                Log::info('SearchTermMiningAgent: Added keyword', [
                    'keyword' => $keyword,
                    'match_type' => $matchTypeName,
                    'ad_group' => $adGroupResourceName,
                ]);

                // Track in Keyword model for portfolio visibility
                try {
                    Keyword::updateOrCreate(
                        ['customer_id' => $customer->id, 'keyword_text' => $keyword, 'ad_group_resource_name' => $adGroupResourceName],
                        ['match_type' => $matchTypeName, 'status' => 'active', 'source' => 'mined', 'criterion_resource_name' => $resourceName, 'added_by_agent' => 'SearchTermMiningAgent']
                    );
                } catch (\Throwable $trackingError) {
                    report($trackingError);
                    Log::debug('SearchTermMiningAgent: Could not track keyword in model', ['error' => $trackingError->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            report($e);
            // Might fail if keyword already exists, which is fine
            Log::debug('SearchTermMiningAgent: Could not add keyword (may already exist)', [
                'keyword' => $keyword,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add a search term as a keyword with the specified match type for Microsoft Ads.
     */
    protected function addAsMicrosoftKeyword(Customer $customer, string $adGroupId, string $keyword, string $matchType, array &$results): void
    {
        try {
            $service = new \App\Services\MicrosoftAds\AdGroupService($customer);
            $resourceName = $service->addKeyword($adGroupId, $keyword, $matchType);

            if ($resourceName) {
                $results['keywords_added'][] = [
                    'keyword' => $keyword,
                    'match_type' => strtoupper($matchType),
                    'resource_name' => $resourceName,
                ];

                Log::info('SearchTermMiningAgent: Added keyword to Microsoft Ads', [
                    'keyword' => $keyword,
                    'match_type' => strtoupper($matchType),
                    'ad_group' => $adGroupId,
                ]);

                Keyword::updateOrCreate(
                    ['customer_id' => $customer->id, 'keyword_text' => $keyword, 'ad_group_resource_name' => $adGroupId],
                    ['match_type' => strtoupper($matchType), 'status' => 'active', 'source' => 'mined', 'criterion_resource_name' => $resourceName, 'added_by_agent' => 'SearchTermMiningAgent']
                );
            }
        } catch (\Throwable $e) {
            report($e);
            Log::debug('SearchTermMiningAgent: Could not add Microsoft keyword (may already exist)', [
                'keyword' => $keyword,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add a search term as a negative keyword for Microsoft Ads.
     */
    protected function addAsMicrosoftNegative(Customer $customer, string $campaignId, string $keyword, string $reason, array &$results): void
    {
        try {
            $service = new \App\Services\MicrosoftAds\AdGroupService($customer);
            // using the campaign-level association
            $resourceName = $service->addNegativeKeyword($campaignId, $keyword, 'Exact', true);

            if ($resourceName) {
                $results['negatives_added'][] = [
                    'keyword' => $keyword,
                    'reason' => $reason,
                    'resource_name' => $resourceName,
                ];

                Log::info('SearchTermMiningAgent: Added Microsoft negative keyword', [
                    'keyword' => $keyword,
                    'reason' => $reason,
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
            Log::debug('SearchTermMiningAgent: Could not add Microsoft negative keyword (may already exist)', [
                'keyword' => $keyword,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add a search term as a negative keyword.
     */
    protected function addAsNegative(Customer $customer, string $customerId, string $campaignResourceName, string $keyword, string $reason, array &$results, string $matchTypeName = 'PHRASE'): void
    {
        $matchType = match (strtoupper($matchTypeName)) {
            'EXACT' => KeywordMatchType::EXACT,
            'BROAD' => KeywordMatchType::BROAD,
            default => KeywordMatchType::PHRASE,
        };
        if ($this->googleKeywordProposals !== null) {
            $key = 'negative|'.mb_strtolower($keyword).'|'.$matchType;
            $this->googleKeywordProposals[$key] = ['action' => 'negative', 'text' => $keyword,
                'match_type' => KeywordMatchType::name($matchType), 'scope' => 'campaign', 'reason' => $reason];

            return;
        }
        if (! $this->googleKeywordBatchApproved) {
            $results['requires_review'] = true;
            $results['review_reason'] = 'Google keyword exclusions require a verified campaign portfolio assessment.';

            return;
        }

        try {
            $resourceName = $this->ads()->keywords($customer)
                ->addNegativeKeyword($customerId, $campaignResourceName, $keyword, $matchType);

            if ($resourceName) {
                $results['negatives_added'][] = [
                    'keyword' => $keyword,
                    'reason' => $reason,
                    'resource_name' => $resourceName,
                ];

                Log::info('SearchTermMiningAgent: Added negative keyword', [
                    'keyword' => $keyword,
                    'reason' => $reason,
                    'campaign' => $campaignResourceName,
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
            // Might fail if negative already exists
            Log::debug('SearchTermMiningAgent: Could not add negative (may already exist)', [
                'keyword' => $keyword,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
