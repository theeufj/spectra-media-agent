<?php

namespace App\Services\Creative;

use App\Jobs\GenerateAdCopy;
use App\Jobs\GenerateImage;
use App\Jobs\GenerateVideo;
use App\Jobs\ReviewCreativeSet;
use App\Models\CreativeBrief;
use App\Models\Strategy;
use App\Services\Campaigns\CampaignResourceInventory;
use App\Services\Campaigns\CollateralPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreativeRevisions
{
    /** Keep the last approved assets available while rendering changed parts of a brief. */
    public function edit(Strategy $strategy, array $brief): array
    {
        return DB::transaction(function () use ($strategy, $brief) {
            $locked = Strategy::lockForUpdate()->findOrFail($strategy->id);
            $fields = array_keys(array_filter($brief, fn ($value, $field) => $locked->getAttribute($field) !== $value, ARRAY_FILTER_USE_BOTH));
            if ($fields === []) {
                return [];
            }
            $locked->fill($brief)->save();
            if ($locked->signed_off_at) {
                $this->stage($locked, $fields);
            }

            return $fields;
        });
    }

    public function draft(Strategy $strategy, array $fields): void
    {
        DB::transaction(fn () => $this->stage(Strategy::lockForUpdate()->findOrFail($strategy->id), $fields));
    }

    /** A customer may approve generating a variant; newly generated claims always need release review. */
    public function actionBrief(CreativeBrief $brief): void
    {
        DB::transaction(function () use ($brief) {
            $brief = CreativeBrief::lockForUpdate()->findOrFail($brief->id);
            if (! in_array($brief->status, ['pending', 'in_review'], true)) {
                return;
            }
            $strategies = $brief->campaign->strategies()->whereNotNull('signed_off_at')
                ->when($brief->context['strategy_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
                ->orderBy('id')->lockForUpdate()->get()->filter(fn ($strategy) => CampaignResourceInventory::platform($strategy) === $brief->platform);
            if ($strategies->count() !== 1) {
                throw ValidationException::withMessages(['brief' => 'A single reviewed strategy on this platform is needed to prepare this brief.']);
            }
            $strategy = $strategies->first();
            if ($brief->brief_type === 'pmax_replacement' && isset($brief->context['release'])
                && $brief->context['release'] !== ($strategy->creative_approval['fingerprint'] ?? 'legacy')) {
                throw ValidationException::withMessages(['brief' => 'This recommendation refers to an earlier campaign release.']);
            }
            $field = match ($brief->context['field_type'] ?? '') {
                'MARKETING_IMAGE', 'SQUARE_MARKETING_IMAGE', 'PORTRAIT_MARKETING_IMAGE' => 'imagery_strategy',
                'YOUTUBE_VIDEO' => 'video_strategy',
                default => 'ad_copy_strategy',
            };
            $this->stage($strategy, [$field], $brief);
            $brief->markActioned();
        });
    }

    private function stage(Strategy $strategy, array $fields, ?CreativeBrief $brief = null): void
    {
        $campaign = $strategy->campaign;
        if (in_array($strategy->creative_publication['phase'] ?? '', ['staging', 'awaiting_platform_review'], true)) {
            throw ValidationException::withMessages(['creative' => 'Finish the current platform review before starting another revision.']);
        }
        $previous = $strategy->creative_revision ?? [];
        if (($previous['status'] ?? null) === 'draft') {
            $fields = array_values(array_unique([...$fields, ...$previous['affected']]));
        }
        $id = (string) Str::uuid();
        $strategy->forceFill(['creative_revision' => ['id' => $id, 'affected' => $fields, 'status' => 'draft', 'brief_id' => $brief?->id,
            'created_at' => now()->toIso8601String(), 'previous_approval' => $strategy->creative_approval]])->save();
        if (in_array('ad_copy_strategy', $fields, true)) {
            GenerateAdCopy::dispatch($campaign, $strategy, $strategy->platform, revisionId: $id)->afterCommit();
        }
        if (in_array('imagery_strategy', $fields, true)) {
            $strategy->forceFill(['creative_candidates' => null, 'creative_concepts' => null, 'creative_review' => [
                'run_id' => $id, 'status' => 'pending', 'expected_images' => 1, 'started_at' => now()->toIso8601String(), 'retried_slots' => [],
            ]])->save();
            GenerateImage::dispatch($campaign, $strategy, 0, $id, reviewFeedback: $brief?->ai_brief)->afterCommit();
            ReviewCreativeSet::dispatch($strategy, $id)->delay(now()->addMinutes(2))->afterCommit();
        }
        if (in_array('video_strategy', $fields, true) && $strategy->supportsVideo() && $campaign->allowsAutomaticVideo()
            && CollateralPlan::legacyVideoBrief($strategy->video_strategy ?? '')) {
            $platform = preg_match('/facebook|meta|instagram/i', $strategy->platform) ? 'Facebook Ads' : 'Google Ads (Performance Max)';
            $variation = 1 + (int) $campaign->videoCollaterals()->where('platform', $platform)->max('variation_index');
            GenerateVideo::dispatch($campaign, $strategy, $platform, $variation, revisionId: $id)->afterCommit();
        }
    }
}
