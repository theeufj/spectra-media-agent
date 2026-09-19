<?php

namespace App\Services\Testing;

/** Review real rendered outputs against a fixed set, not the prompt's self-rating. */
class CreativeBenchmark
{
    public const CRITERIA = ['offer_specificity', 'visual_distinction', 'brand_fidelity', 'composition', 'placement_fit', 'claim_accuracy'];

    public function assess(array $manifest, array $fixtures): array
    {
        $issues = [];
        $scores = [];
        $cases = collect($manifest['cases'] ?? [])->keyBy('fixture_id');
        if (empty($manifest['reviewer']) || empty($manifest['reviewed_at'])) {
            $issues[] = 'Rendered assets need a named reviewer and review date.';
        }
        $fingerprint = $this->fingerprint();
        if (($manifest['prompt_fingerprint'] ?? '') !== $fingerprint) {
            $issues[] = 'Review does not match the current prompt revision.';
        }
        foreach ($fixtures as $fixture) {
            $case = $cases->get($fixture['id']);
            if (! $case || count($case['assets'] ?? []) < 3 || empty($case['notes'])) {
                $issues[] = $fixture['id'].': review three distinct rendered concepts and record observations.';

                continue;
            }
            if (count(array_unique(array_column($case['assets'], 'sha256'))) < 3) {
                $issues[] = $fixture['id'].': the three concepts must have distinct rendered files.';
            }
            $images = 0;
            $videos = 0;
            foreach ($case['assets'] as $asset) {
                if (empty($asset['path']) || ! is_file($asset['path']) || empty($asset['sha256']) || hash_file('sha256', $asset['path']) !== $asset['sha256']) {
                    $issues[] = $fixture['id'].': reviewed asset is missing or changed.';

                    continue;
                }
                $size = @getimagesize($asset['path']);
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($asset['path']);
                if ($size && min($size[0], $size[1]) >= 300) {
                    $images++;
                    if ($fixture['placement'] === 'google-search' && ($asset['layout'] ?? null) !== 'clean') {
                        $issues[] = $fixture['id'].': Search image assets must have no composited overlays.';
                    }
                } elseif (is_string($mime) && str_starts_with($mime, 'video/')) {
                    $videos++;
                } else {
                    $issues[] = $fixture['id'].': asset is not a usable rendered image or video.';
                }
            }
            if ($images < 3 || ($fixture['video'] && $videos < 1)) {
                $issues[] = $fixture['id'].': include three rendered images and the requested video.';
            }
            foreach (self::CRITERIA as $criterion) {
                $score = $case['scores'][$criterion] ?? null;
                if (! is_int($score) || $score < 4 || $score > 5) {
                    $issues[] = $fixture['id'].': '.$criterion.' needs at least 4/5.';
                }
                $scores[] = is_int($score) ? $score : 0;
            }
            if ($fixture['video'] && empty($case['video_reviewed'])) {
                $issues[] = $fixture['id'].': review the opening hook, demonstration, audio and close in the rendered video.';
            }
        }

        return ['passed' => $issues === [], 'issues' => $issues, 'mean_score' => $scores ? array_sum($scores) / count($scores) : 0, 'prompt_fingerprint' => $fingerprint];
    }

    public function fingerprint(): string
    {
        $hashes = [];
        foreach (['StrategyPrompt', 'ImagePrompt', 'ImagePromptSplitterPrompt', 'CreativeVariant', 'VideoScriptPrompt', 'VideoFromScriptPrompt'] as $prompt) {
            $hashes[] = hash_file('sha256', app_path("Prompts/{$prompt}.php"));
        }

        return hash('sha256', implode('', $hashes));
    }
}
