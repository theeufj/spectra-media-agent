<?php

namespace App\Services\Brands;

use App\Models\BrandGuideline;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BrandProfileReview
{
    public const FIELDS = ['brand_voice', 'tone_attributes', 'writing_patterns', 'color_palette', 'typography', 'visual_style', 'messaging_themes', 'unique_selling_propositions', 'target_audience', 'competitor_differentiation', 'brand_personality', 'do_not_use', 'service_lines'];

    public function profile(BrandGuideline $brand): array
    {
        return Arr::only($brand->toArray(), self::FIELDS);
    }

    /** Lists are one decision; associative properties can be corrected independently. */
    public function changes(array $before, array $after, string $prefix = ''): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($old === $new) {
                continue;
            }
            if (is_array($old) && is_array($new) && ! array_is_list($old) && ! array_is_list($new)) {
                $changes = [...$changes, ...$this->changes($old, $new, $path)];
            } else {
                $changes[] = ['path' => $path, 'before' => $old, 'after' => $new];
            }
        }

        return $changes;
    }

    public function propose(BrandGuideline $brand, array $profile, array $metadata): BrandGuideline
    {
        return DB::transaction(function () use ($brand, $profile, $metadata) {
            $locked = BrandGuideline::query()->whereKey($brand->id)->lockForUpdate()->firstOrFail();
            $saved = $this->profile($locked);
            $overrides = $locked->manual_overrides ?? [];
            // Older verified rows did not record which fields were edited. Protect
            // all their confirmed information until the owner accepts suggestions.
            if ($locked->user_verified && $locked->approved_version === null && $overrides === []) {
                $overrides = $saved;
            }
            $draft = $profile;
            uksort($overrides, fn ($a, $b) => substr_count($a, '.') <=> substr_count($b, '.'));
            foreach ($overrides as $path => $value) {
                Arr::set($draft, $path, $value);
            }
            $changes = $this->changes($saved, $draft);
            $suggestions = $this->changes($draft, $profile);
            $locked->fill($metadata + [
                'proposed_profile' => $draft,
                'source_suggestions' => $suggestions,
                'extraction_changes' => $changes,
                'manual_overrides' => $overrides,
                'profile_version' => $locked->profile_version + 1,
                'user_verified' => false,
                'approved_version' => null,
            ])->save();

            return $locked;
        });
    }

    public function saveDraft(BrandGuideline $brand, array $profile, ?int $version): BrandGuideline
    {
        return DB::transaction(function () use ($brand, $profile, $version) {
            $locked = BrandGuideline::query()->whereKey($brand->id)->lockForUpdate()->firstOrFail();
            $this->checkVersion($locked, $version);
            $current = $locked->proposed_profile ?? $this->profile($locked);
            $draft = array_replace($current, $profile);
            $overrides = $locked->manual_overrides ?? [];
            foreach ($this->changes($current, $draft) as $change) {
                $overrides[$change['path']] = $change['after'];
            }
            $locked->fill([
                'proposed_profile' => $draft,
                'manual_overrides' => $overrides,
                'source_suggestions' => collect($locked->source_suggestions ?? [])->map(fn ($change) => ['path' => $change['path'], 'before' => Arr::get($draft, $change['path']), 'after' => $change['after']])->filter(fn ($change) => $change['before'] !== $change['after'])->values()->all(),
                'extraction_changes' => $this->changes($this->profile($locked), $draft),
                'profile_version' => $locked->profile_version + 1,
                'user_verified' => false,
                'approved_version' => null,
            ])->save();

            return $locked;
        });
    }

    public function acceptSuggestions(BrandGuideline $brand, array $paths, ?int $version): void
    {
        DB::transaction(function () use ($brand, $paths, $version) {
            $locked = BrandGuideline::query()->whereKey($brand->id)->lockForUpdate()->firstOrFail();
            $this->checkVersion($locked, $version);
            $draft = $locked->proposed_profile ?? $this->profile($locked);
            $overrides = $locked->manual_overrides ?? [];
            $remaining = [];
            foreach ($locked->source_suggestions ?? [] as $change) {
                if (! in_array($change['path'], $paths, true)) {
                    $remaining[] = $change;

                    continue;
                }
                Arr::set($draft, $change['path'], $change['after']);
                // Accepting one nested suggestion must keep sibling corrections.
                foreach (array_keys($overrides) as $path) {
                    if ($path === $change['path'] || str_starts_with($path, $change['path'].'.')) {
                        unset($overrides[$path]);
                    } elseif (str_starts_with($change['path'], $path.'.')) {
                        Arr::set($overrides[$path], substr($change['path'], strlen($path) + 1), $change['after']);
                    }
                }
            }
            $locked->fill([
                'proposed_profile' => $draft,
                'manual_overrides' => $overrides,
                'source_suggestions' => $remaining,
                'extraction_changes' => $this->changes($this->profile($locked), $draft),
                'profile_version' => $locked->profile_version + 1,
                'user_verified' => false,
                'approved_version' => null,
            ])->save();
        });
    }

    public function approve(BrandGuideline $brand, ?int $version): void
    {
        DB::transaction(function () use ($brand, $version) {
            $locked = BrandGuideline::query()->whereKey($brand->id)->lockForUpdate()->firstOrFail();
            $this->checkVersion($locked, $version);
            $locked->fill(($locked->proposed_profile ?? []) + [
                'proposed_profile' => null,
                'extraction_changes' => [],
                'user_verified' => true,
                'approved_version' => $locked->profile_version,
                'last_verified_at' => now(),
            ])->save();
        });
    }

    private function checkVersion(BrandGuideline $brand, ?int $version): void
    {
        if ($version !== null && $version !== $brand->profile_version) {
            throw ValidationException::withMessages(['profile_version' => 'This profile changed while you were reviewing it. Refresh and review the latest version before saving or approving.']);
        }
    }
}
