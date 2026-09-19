<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Validate the entire model response before writing any member of the set. */
final readonly class StrategyDocument
{
    private function __construct(public array $strategies) {}

    public static function fromArray(array $document, array $enabledPlatforms, float $budget): self
    {
        Validator::make($document, [
            'strategies' => 'required|array|min:1|max:12',
            'strategies.*' => 'required|array',
            'strategies.*.platform' => 'required|string|max:100',
            'strategies.*.ad_copy_strategy' => 'required|string',
            'strategies.*.imagery_strategy' => 'required|string',
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
            $weights[(string) $index] = isset($strategy['daily_budget']) ? (int) round($strategy['daily_budget'] * 100) : 1;
            if (($strategy['targeting']['age_min'] ?? 18) > ($strategy['targeting']['age_max'] ?? 65)) {
                throw ValidationException::withMessages(['strategies' => 'The audience age range is reversed.']);
            }
            if (preg_match('/search|sem/i', $strategy['platform'])) {
                $strategies[$index]['generate_video'] = false;
            }
        }
        $shares = CampaignBudgetService::split((int) round($budget * 100), $weights);
        foreach ($strategies as $index => &$strategy) {
            $strategy['daily_budget'] = $shares[$index] / 100;
        }

        return new self($strategies);
    }
}
