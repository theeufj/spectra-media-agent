<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Validate the entire model response before writing any member of the set. */
final readonly class StrategyDocument
{
    private function __construct(public array $strategies) {}

    public static function preferredGoogleType(Campaign $campaign): ?string
    {
        // Existing placements have their own reviewed objectives and settings.
        if ($campaign->customer?->service_type !== 'managed'
            || $campaign->strategies->contains(fn ($strategy) => str_contains(strtolower($strategy->platform), 'google'))) {
            return null;
        }
        $brief = $campaign->name.' '.$campaign->reason.' '.implode(' ', Arr::wrap($campaign->goals));

        return preg_match('/\b(?:google search|search[- ]only|search campaign|youtube|shopping[- ]only|display[- ]only|app installs|demand gen|local services ads)\b/i', $brief)
            ? null : 'performance_max';
    }

    public static function fromArray(array $document, array $enabledPlatforms, float $budget, bool $requireCreativeCandidates = false, ?string $googleDefault = null): self
    {
        Validator::make($document, [
            'strategies' => 'required|array|min:1|max:12',
            'strategies.*' => 'required|array',
            'strategies.*.platform' => 'required|string|max:100',
            'strategies.*.campaign_type' => 'sometimes|in:search,display,video,shopping,app,demand_gen,local_services,performance_max',
            'strategies.*.ad_copy_strategy' => 'required|string',
            'strategies.*.imagery_strategy' => 'required|string',
            'strategies.*.creative_candidates' => ($requireCreativeCandidates ? 'required' : 'sometimes').'|array|size:6',
            'strategies.*.creative_concepts' => 'sometimes|array|size:3',
            'strategies.*.creative_concepts.*.selling_idea' => 'required|string|max:250',
            'strategies.*.creative_concepts.*.evidence' => 'required|string|max:1200',
            'strategies.*.creative_concepts.*.visual' => 'required|string|max:2000',
            'strategies.*.video_strategy' => 'present|nullable|string',
            'strategies.*.generate_video' => 'required|boolean',
            'strategies.*.bidding_strategy' => 'required|array',
            'strategies.*.bidding_strategy.parameters' => 'sometimes|array',
            'strategies.*.revenue_cpa_multiple' => 'required|numeric|min:0|max:1000',
            'strategies.*.daily_budget' => 'sometimes|numeric|gt:0',
            'strategies.*.targeting' => 'sometimes|array',
            'strategies.*.targeting.age_min' => 'sometimes|integer|min:18|max:65',
            'strategies.*.targeting.age_max' => 'sometimes|integer|min:18|max:65',
            'strategies.*.ad_extensions' => 'sometimes|array',
            'strategies.*.conversion_goals' => 'sometimes|array',
            'strategies.*.landing_page_url' => 'nullable|url:http,https',
        ])->validate();
        $strategies = $document['strategies'];
        $weights = [];
        foreach ($strategies as $index => $strategy) {
            $enabled = collect($enabledPlatforms)->contains(fn ($name) => str_contains(strtolower($strategy['platform']), strtolower($name)));
            if (! $enabled) {
                throw ValidationException::withMessages(['strategies' => 'The strategy selected a disabled platform.']);
            }
            $ideas = array_map(fn ($concept) => mb_strtolower(trim($concept['selling_idea'])), $strategy['creative_concepts'] ?? []);
            if (count(array_unique($ideas)) !== count($ideas)) {
                throw ValidationException::withMessages(['strategies' => 'Creative concepts must test distinct reasons to choose the offer.']);
            }
            $weights[(string) $index] = isset($strategy['daily_budget']) ? (int) round($strategy['daily_budget'] * 100) : 1;
            if (($strategy['targeting']['age_min'] ?? 18) > ($strategy['targeting']['age_max'] ?? 65)) {
                throw ValidationException::withMessages(['strategies' => 'The audience age range is reversed.']);
            }
            $searchLabel = (bool) preg_match('/search|sem/i', $strategy['platform']);
            $type = $strategy['campaign_type'] ?? match (true) {
                (bool) preg_match('/performance.?max|pmax/i', $strategy['platform']) => 'performance_max',
                (bool) preg_match('/demand.?gen/i', $strategy['platform']) => 'demand_gen',
                (bool) preg_match('/youtube|video/i', $strategy['platform']) => 'video',
                (bool) preg_match('/shopping/i', $strategy['platform']) => 'shopping',
                (bool) preg_match('/display/i', $strategy['platform']) => 'display',
                (bool) preg_match('/google|microsoft|bing|search|sem/i', $strategy['platform']) => 'search',
                default => 'display',
            };
            if ($googleDefault === 'performance_max' && preg_match('/google|adwords/i', $strategy['platform'])
                && in_array($type, ['search', 'performance_max'], true)) {
                $strategies[$index]['platform'] = 'Google Ads (Performance Max)';
                $strategies[$index]['bidding_strategy'] = ['name' => 'MAXIMIZE_CONVERSIONS'];
                $type = 'performance_max';
                $searchLabel = false;
            }
            if ($searchLabel && $type !== 'search') {
                throw ValidationException::withMessages(['strategies' => 'A Search strategy must use the search campaign type.']);
            }
            $strategies[$index]['campaign_type'] = $type;
            if (isset($strategy['creative_candidates'])) {
                $strategies[$index]['creative_concepts'] = app(\App\Services\Creative\ConceptSelector::class)->select($strategy['creative_candidates'], $type);
            }
            $strategies[$index]['generate_video'] = match ($type) {
                'search' => false,
                'performance_max' => true,
                default => $strategy['generate_video'],
            };
        }
        $shares = CampaignBudgetService::split((int) round($budget * 100), $weights);
        foreach ($strategies as $index => &$strategy) {
            $strategy['daily_budget'] = $shares[$index] / 100;
        }

        return new self($strategies);
    }
}
