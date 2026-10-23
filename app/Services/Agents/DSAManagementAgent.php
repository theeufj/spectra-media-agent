<?php

namespace App\Services\Agents;

use App\Enums\CampaignStatus;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\CommonServices\AddKeyword;
use App\Services\GoogleAds\CommonServices\GetSearchTermsReport;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use Google\Ads\GoogleAds\V26\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** DSA requires its own approved funding and serving assessment before any automatic launch. */
class DSAManagementAgent
{
    public function manage(Customer $customer): array
    {
        if (! $customer->google_ads_customer_id || ! $customer->website) {
            return ['skipped' => true, 'reason' => 'No Google Ads account or website'];
        }

        return $this->defer($customer, 'dsa_launch_requires_review',
            'Automatic Dynamic Search Ads creation is on hold. A separate campaign needs explicitly approved funding and a faithful DSA reach assessment; an ordinary keyword forecast does not verify its URL targeting.');
    }

    public function promoteHighPerformingTerms(Customer $customer): array
    {
        if (! $customer->google_ads_customer_id) {
            return ['skipped' => true, 'reason' => 'No Google Ads account'];
        }
        if ($customer->service_type !== 'managed' || $customer->is_sandbox) {
            return $this->defer($customer, 'dsa_keyword_promotion_requires_review', 'Automatic DSA keyword promotion is available only for customers under ongoing management.');
        }
        $activity = AgentActivity::where('customer_id', $customer->id)->where('action', 'dsa_campaign_setup')->latest()->first();
        $resource = $activity?->details['dsa_campaign_resource'] ?? null;
        $customerId = $customer->cleanGoogleCustomerId();
        if (! is_string($resource) || ! preg_match('~^customers/'.preg_quote($customerId, '~').'/campaigns/\d+$~', $resource)) {
            return $this->defer($customer, 'dsa_keyword_promotion_requires_review', 'No existing Dynamic Search Ads campaign in this customer account could be verified.');
        }

        $campaigns = $customer->campaigns()->where('status', CampaignStatus::Active->value)
            ->where('primary_status', 'ELIGIBLE')->whereNotNull('google_ads_campaign_id')->get();
        $promoted = [];
        $deferred = [];
        $errors = [];
        try {
            $terms = app(GetSearchTermsReport::class, ['customer' => $customer])($customerId, $resource, 'LAST_30_DAYS');
            // Clicks alone are not evidence of commercial intent. Promote only terms that have converted.
            $candidates = array_filter($terms, fn ($term) => ($term['campaign_resource_name'] ?? null) === $resource
                && ($term['clicks'] ?? 0) >= 5 && ($term['conversions'] ?? 0) > 0);
            $cacheKey = 'dsa_promoted:'.$customer->id;
            $alreadyAdded = Cache::get($cacheKey, []);
            foreach ($campaigns as $campaign) {
                $adGroup = $campaign->strategies()->where('platform', 'Google Ads')->whereNotNull('google_ads_ad_group_id')->latest()->value('google_ads_ad_group_id');
                if (! is_string($adGroup) || ! preg_match('~^customers/'.preg_quote($customerId, '~').'/adGroups/\d+$~', $adGroup)) {
                    continue;
                }
                $proposals = [];
                foreach ($candidates as $term) {
                    $text = trim($term['search_term'] ?? '');
                    $key = $campaign->id.'|'.$adGroup.'|'.mb_strtolower($text);
                    // Legacy cache entries contained plain text; honour them without conflating new destinations.
                    if ($text === '' || in_array($text, $alreadyAdded, true) || in_array($key, $alreadyAdded, true)) {
                        continue;
                    }
                    $proposals[$key] = ['action' => 'add', 'text' => $text, 'match_type' => 'PHRASE',
                        'ad_group_resource' => $adGroup,
                        'selection_reason' => 'Observed search term from this customer’s website-targeted DSA campaign with at least five clicks and recorded primary conversions.'];
                }
                if ($proposals === []) {
                    continue;
                }
                if (CampaignSpendGuardrails::automaticChangesSuspended($campaign)) {
                    $assessment = ['ready' => false, 'message' => 'Automatic DSA keyword promotion is suspended by a trial or spending hold.'];
                } else {
                    $keywords = $this->existingGoogleKeywords($campaign);
                    $proposals = array_filter($proposals, function ($proposal) use ($keywords): bool {
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
                    if ($proposals === []) {
                        continue;
                    }
                    $assessment = app(GoogleSearchMutationGuard::class)->assess($campaign, array_values($proposals));
                }
                if (! ($assessment['ready'] ?? false)) {
                    $reason = $assessment['message'] ?? 'Destination Search demand and affordable traffic could not be verified.';
                    $deferred[] = ['campaign_id' => $campaign->id, 'message' => $reason];
                    $this->defer($customer, 'dsa_keyword_promotion_requires_review', $reason, $campaign,
                        ['proposals' => array_values($proposals), 'assessment' => $assessment]);

                    continue;
                }
                foreach ($proposals as $key => $proposal) {
                    $current = $campaign->fresh(['customer']);
                    if (! $current || ! $current->customer || $current->status !== CampaignStatus::Active || $current->customer->service_type !== 'managed'
                        || $current->customer->cleanGoogleCustomerId() !== $customerId
                        || CampaignSpendGuardrails::automaticChangesSuspended($current)) {
                        $reason = 'The destination campaign is no longer eligible for automatic DSA keyword promotion.';
                        $applied = array_values(array_filter($promoted, fn ($keyword) => $keyword['campaign_id'] === $campaign->id));
                        if ($applied !== []) {
                            $this->recordPartialPromotion($campaign, $reason, ['planned_changes' => array_values($proposals),
                                'failed_change' => $proposal, 'applied_keywords' => $applied,
                                'actual_portfolio_assessment' => ['ready' => false, 'message' => $reason]]);
                        }
                        $deferred[] = ['campaign_id' => $campaign->id, 'message' => $reason];
                        $this->defer($customer, 'dsa_keyword_promotion_requires_review', $reason, $campaign);

                        break;
                    }
                    try {
                        $result = app(AddKeyword::class, ['customer' => $customer])($customerId, $adGroup, $proposal['text'], KeywordMatchType::PHRASE);
                    } catch (\Throwable $e) {
                        report($e);
                        $errors[] = $e->getMessage();
                        $result = null;
                    }
                    if ($result) {
                        $alreadyAdded[] = $key;
                        $promoted[] = ['keyword' => $proposal['text'], 'campaign_id' => $campaign->id, 'resource_name' => $result];
                    } else {
                        $reason = 'A DSA keyword promotion was not confirmed. Remaining promotions are held until the actual destination portfolio is reviewed.';
                        try {
                            $readback = app(GoogleSearchMutationGuard::class)->assess($campaign, []);
                        } catch (\Throwable $e) {
                            report($e);
                            $readback = ['ready' => false, 'message' => 'The actual destination keyword portfolio could not be verified.'];
                        }
                        $details = ['planned_changes' => array_values($proposals), 'failed_change' => $proposal,
                            'applied_keywords' => array_values(array_filter($promoted, fn ($applied) => $applied['campaign_id'] === $campaign->id)),
                            'actual_portfolio_assessment' => $readback];
                        $this->recordPartialPromotion($campaign, $reason, $details);
                        $deferred[] = ['campaign_id' => $campaign->id, 'message' => $reason];
                        $this->defer($customer, 'dsa_keyword_promotion_requires_review', $reason, $campaign, $details);

                        break;
                    }
                }
            }
            Cache::put($cacheKey, $alreadyAdded, now()->addDays(30));
        } catch (\Throwable $e) {
            report($e);
            $errors[] = $e->getMessage();
            $deferred[] = ['message' => 'Existing DSA terms or destination reach could not be verified.'];
            $this->defer($customer, 'dsa_keyword_promotion_requires_review', 'Existing DSA terms or destination reach could not be verified.');
        }
        if ($promoted !== []) {
            AgentActivity::record('dsa', 'dsa_terms_promoted', 'Promoted '.count($promoted).' converting DSA search terms after destination demand and affordable traffic verification.',
                $customer->id, null, ['promoted' => $promoted, 'deferred' => $deferred], $deferred === [] ? 'completed' : 'needs_review');
        }

        return ['promoted' => count($promoted), 'terms' => $promoted, 'requires_review' => $deferred !== [], 'deferred' => $deferred, 'errors' => $errors];
    }

    protected function existingGoogleKeywords(Campaign $campaign): array
    {
        $snapshot = app(InspectSearchDelivery::class, ['customer' => $campaign->customer])->inspect($campaign);
        if (! is_array($snapshot) || ! is_array($snapshot['keywords'] ?? null)) {
            throw new \RuntimeException('Existing destination Google keywords could not be verified before DSA promotion.');
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

    private function recordPartialPromotion(Campaign $campaign, string $reason, array $details): void
    {
        DB::transaction(function () use ($campaign, $reason, $details): void {
            $current = Campaign::whereKey($campaign->id)->where('customer_id', $campaign->customer_id)->lockForUpdate()->first();
            if (! $current) {
                return;
            }
            $state = $current->search_delivery_state ?? [];
            $state['status'] = 'needs_review';
            $state['mutation_allowed'] = false;
            $state['verification']['errors'][] = ['code' => 'keyword_batch_partial_apply', 'message' => $reason,
                'agent' => 'dsa', 'checked_at' => now()->toIso8601String(), ...$details];
            $current->forceFill(['search_delivery_state' => $state])->save();
        });
    }

    private function defer(Customer $customer, string $reason, string $message, ?Campaign $campaign = null, array $details = []): array
    {
        if (! AgentActivity::where('customer_id', $customer->id)->where('action', $reason)
            ->where('campaign_id', $campaign?->id)
            ->where('created_at', '>=', now()->subDay())->exists()) {
            AgentActivity::record('dsa', $reason, $message, $customer->id, $campaign?->id,
                ['requires_review' => true, 'google_customer_id' => $customer->cleanGoogleCustomerId()] + $details, 'needs_review');
        }

        return ['skipped' => true, 'requires_review' => true, 'reason' => $reason, 'message' => $message,
            'created' => [], 'promoted' => 0];
    }
}
