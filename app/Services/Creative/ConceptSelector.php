<?php

namespace App\Services\Creative;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Choose a varied set before spending on renders. No additional model calls. */
class ConceptSelector
{
    public function select(array $candidates, string $type): array
    {
        Validator::make(['candidates' => $candidates], [
            'candidates' => 'required|array|size:6',
            'candidates.*.selling_idea' => 'required|string|max:250',
            'candidates.*.evidence' => 'required|string|max:1200',
            'candidates.*.visual' => 'required|string|max:2000',
            'candidates.*.subject' => 'required|string|max:250',
            'candidates.*.visual_style' => 'required|in:editorial_photo,detail_photo,still_life,illustration,three_dimensional',
            'candidates.*.composition' => 'required|in:close_up,environmental,overhead,isolated_hero,asymmetric',
            'candidates.*.layout' => 'required|in:clean,statement,editorial',
            'candidates.*.headline' => 'required|string|max:70',
            'candidates.*.supporting_copy' => 'required|string|max:120',
            'candidates.*.cta' => 'required|string|max:24',
        ])->validate();

        $candidates = array_values($candidates);
        $best = null;
        $bestScore = -INF;
        foreach ($candidates as $i => $a) {
            foreach ($candidates as $j => $b) {
                foreach ($candidates as $k => $c) {
                    if ($i >= $j || $j >= $k) {
                        continue;
                    }
                    $set = [$a, $b, $c];
                    if ($type === 'search' && collect($set)->contains(fn ($v) => ! in_array($v['visual_style'], ['editorial_photo', 'detail_photo', 'still_life'], true))) {
                        continue;
                    }
                    $score = 0;
                    foreach ([[$a, $b], [$a, $c], [$b, $c]] as [$left, $right]) {
                        // Different wording alone cannot pass a set of near-identical scenes.
                        if ($this->similarity($left['selling_idea'], $right['selling_idea']) > .7
                            || $this->similarity($left['subject'], $right['subject']) > .55
                            || $this->similarity($left['visual'], $right['visual']) > .65) {
                            continue 2;
                        }
                        $score += ($left['visual_style'] !== $right['visual_style'] ? 3 : 0)
                            + ($left['composition'] !== $right['composition'] ? 2 : 0)
                            + 1 - $this->similarity($left['visual'], $right['visual']);
                    }
                    if (count(array_unique(array_column($set, 'composition'))) < 2
                        || count(array_unique(array_column($set, 'visual_style'))) < 2) {
                        continue;
                    }
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = array_map(fn ($index) => array_merge($candidates[$index], ['candidate_id' => $index + 1]), [$i, $j, $k]);
                    }
                }
            }
        }
        if ($best === null) {
            throw ValidationException::withMessages(['creative_candidates' => 'The six ideas did not contain three sufficiently different visual directions. Regenerate the creative plan.']);
        }

        return $best;
    }

    private function similarity(string $a, string $b): float
    {
        $tokens = fn ($text) => array_values(array_unique(array_diff(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [], ['a', 'an', 'the', 'and', 'of', 'in', 'on', 'with', 'for', 'to', 'at'])));
        $left = $tokens($a);
        $right = $tokens($b);

        return count(array_intersect($left, $right)) / max(1, min(count($left), count($right)));
    }
}
