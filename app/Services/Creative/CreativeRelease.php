<?php

namespace App\Services\Creative;

use App\Jobs\ReviewCreativeRelease;
use App\Models\AdCopy;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\GoogleAds\PerformanceMaxServices\PerformanceMaxLogo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * The final customer action approves the selection that was reviewed and shown.
 * Keep deployment inputs pinned while reviews, uploads and provisioning run.
 */
class CreativeRelease
{
    public function selection(Strategy $strategy): array
    {
        $platform = strtolower($strategy->platform);
        $searchOffers = $strategy->campaign_type === 'search' && str_contains($platform, 'google')
            ? ($strategy->bidding_strategy['search_offer_groups'] ?? []) : [];
        $copy = $searchOffers === []
            ? $strategy->adCopies()->where('should_deploy', true)->orderBy('id')->get()
            : collect();
        $images = str_contains($platform, 'linkedin') ? collect() : ImageCollateral::forStrategy($strategy)
            ->where('is_active', true)->where('should_deploy', true)->orderBy('id')->get();
        $videos = ! $strategy->supportsVideo() || preg_match('/linkedin|microsoft|bing/i', $platform)
            ? collect() : VideoCollateral::forStrategy($strategy)->where('campaign_id', $strategy->campaign_id)
                ->where('is_active', true)->where('should_deploy', true)->whereIn('status', ['pending', 'generating', 'completed'])->orderBy('id')->get();
        $contents = [
            'strategy' => [$strategy->id, $strategy->platform, $strategy->campaign_type],
            'destination' => ($strategy->bidding_strategy['landing_page_url'] ?? null)
                ?: ($strategy->campaign->landing_page_url ?: $strategy->campaign->customer->website),
            'search_offers' => $searchOffers,
            'copy' => $copy->map(fn (AdCopy $row) => [$row->id, $row->platform, $row->headlines, $row->long_headlines, $row->descriptions])->all(),
            'images' => $images->map(fn (ImageCollateral $row) => [$row->id, $row->s3_path, $row->cloudfront_url, $row->format])->all(),
            // The platform video ID arrives after approval. The actual stored
            // media, status and script must still match the review.
            'videos' => $videos->map(fn (VideoCollateral $row) => [$row->id, $row->platform, $row->status, $row->s3_path, $row->cloudfront_url, $row->script])->all(),
        ];

        $bannerSets = $images->filter(fn (ImageCollateral $image) => $image->google_display_banners !== null)
            ->mapWithKeys(fn (ImageCollateral $image) => [$image->id => $image->google_display_banners]);
        if ($bannerSets->isNotEmpty()) {
            $contents['google_display_banners'] = $bannerSets->all();
        }
        if ($strategy->campaign_type === 'performance_max') {
            $logos = $images->filter(fn (ImageCollateral $image) => PerformanceMaxLogo::designated($image))
                ->mapWithKeys(fn (ImageCollateral $image) => [$image->id => PerformanceMaxLogo::digest($image, $strategy)]);
            if ($logos->isNotEmpty()) {
                $contents['business_logos'] = $logos->all();
            }
        }

        $nativeCta = null;
        $fingerprint = hash('sha256', json_encode($contents, JSON_THROW_ON_ERROR));
        if (str_contains($platform, 'google') && in_array($strategy->campaign_type, ['display', 'performance_max'], true)) {
            // A release approved before native buttons shipped used Google's automatic selection.
            // Keep it only while its original copy, media, destination and logo fingerprint still matches.
            $approval = $strategy->creative_approval;
            $legacyApproval = $bannerSets->isEmpty() && ! isset($approval['google_native_cta'])
                && ($approval['fingerprint'] ?? null) === $fingerprint;
            if (! $legacyApproval) {
                $nativeCta = GoogleCreativeCta::label($strategy);
                $contents['google_native_cta'] = $nativeCta;
                $fingerprint = hash('sha256', json_encode($contents, JSON_THROW_ON_ERROR));
            }
        }

        // A release already approved on the former fingerprint can keep serving. A change
        // to any of its legacy inputs or a new approval switches to the versioned evidence scope.
        $previous = $strategy->creative_approval ?? [];
        $legacy = ! isset($previous['mandate']) && ($previous['fingerprint'] ?? null) === $fingerprint;
        $campaign = $strategy->campaign;
        $mandate = [
            'version' => 1, 'evidence' => hash('sha256', json_encode(app(\App\Services\Campaigns\AdvertisingEvidence::class)->context($campaign, $strategy), JSON_THROW_ON_ERROR)),
            'brand_version' => $campaign->customer?->brandGuideline?->approved_version,
            'brief' => [$strategy->ad_copy_strategy, $strategy->imagery_strategy, $strategy->video_strategy],
            'offer' => $campaign->product_focus, 'audience' => $campaign->target_market,
            'geography' => $campaign->geographic_targeting, 'destination' => $contents['destination'],
            'bidding' => $strategy->bidding_strategy, 'goal' => $strategy->conversion_goals,
            'approved_budget' => \App\Services\Campaigns\CampaignSpendGuardrails::moneyToMicros($campaign->approved_daily_budget), 'strategy_budget' => \App\Services\Campaigns\CampaignSpendGuardrails::moneyToMicros($strategy->daily_budget),
        ];
        if (! $legacy) {
            $fingerprint = hash('sha256', json_encode([$contents, $mandate], JSON_THROW_ON_ERROR));
        }

        return [
            'mandate' => $mandate,
            'copy_digest' => hash('sha256', json_encode([$contents['copy'], $contents['search_offers']], JSON_THROW_ON_ERROR)),
            'legacy' => $legacy,
            'fingerprint' => $fingerprint,
            'copy' => $copy,
            'images' => $images,
            'videos' => $videos,
            'search_offers' => $searchOffers,
            'google_native_cta' => $nativeCta,
        ];
    }

    public function state(Strategy $strategy, bool $queueReview = false): array
    {
        $selection = $this->selection($strategy);
        $fingerprint = $selection['fingerprint'];
        $state = fn (string $status, array $issues) => ['fingerprint' => $fingerprint, 'status' => $status, 'issues' => $issues];

        if (! $strategy->signed_off_at || ($selection['copy']->isEmpty() && $selection['search_offers'] === [])) {
            return $state('needs_review', ['Select ad copy before launching.']);
        }
        if ($strategy->campaign_type === 'performance_max' && ! $strategy->hasDeploymentFootprint()
            && ! $selection['images']->contains(fn (ImageCollateral $image) => PerformanceMaxLogo::digest($image, $strategy) !== null)) {
            return $state('needs_review', [PerformanceMaxLogo::REQUIRED]);
        }
        if (in_array($strategy->creative_review['status'] ?? null, ['pending', 'reviewing', 'revising'], true)
            || $selection['videos']->contains(fn (VideoCollateral $video) => $video->status !== 'completed')) {
            return $state('pending', ['Your selected creative is still being prepared.']);
        }
        $review = $strategy->creative_release_review;
        if ($this->reviewIsCurrent($review ?? [], $fingerprint)) {
            return $state($review['status'], $review['issues']);
        }
        if ($queueReview) {
            ReviewCreativeRelease::dispatch($strategy->id, $fingerprint)->afterCommit();
        }

        return $state('pending', ['Your selected ads are waiting for a quality review.']);
    }

    public function reviewIsCurrent(array $review, string $fingerprint): bool
    {
        if (($review['fingerprint'] ?? null) !== $fingerprint) {
            return false;
        }
        if (($review['status'] ?? null) === 'unavailable') {
            return ($review['retry_after'] ?? 0) > now()->timestamp;
        }
        // Older reviews stored the same sentence for a timeout and a rejection.
        // Recheck only an entirely ambiguous result. Preserve specific rejections.
        $issues = $review['issues'] ?? [];
        $legacyAmbiguous = ($review['status'] ?? null) === 'needs_review' && ! isset($review['copy_review_version'])
            && is_array($issues) && $issues !== [] && array_all($issues, static fn ($issue) => is_string($issue)
                && preg_match('/^Ad copy #\d+ did not pass the factual and placement checks\. Regenerate or deselect it\.$/D', $issue));

        return ! $legacyAmbiguous;
    }

    public function issues(Strategy $strategy): array
    {
        return $this->state($strategy)['issues'];
    }

    public function assertReady(Strategy $strategy, ?string $postedFingerprint): void
    {
        $state = $this->state($strategy, true);
        if ($state['status'] !== 'passed') {
            throw ValidationException::withMessages(['creative' => $state['issues']]);
        }
        if ($postedFingerprint !== $state['fingerprint']) {
            throw ValidationException::withMessages(['creative' => 'Your selected ads changed. Review the current selection before launching.']);
        }
    }

    public function approve(Strategy $strategy, ?string $postedFingerprint = null, ?array $reviewedPairs = null): void
    {
        $this->assertReady($strategy, $postedFingerprint);
        $selection = $this->selection($strategy);
        if ($selection['fingerprint'] !== $postedFingerprint) {
            throw ValidationException::withMessages(['creative' => 'Your selected ads changed. Review the current selection before launching.']);
        }

        $metaPairs = [];
        if (preg_match('/facebook|instagram|meta/i', $strategy->platform) && ($selection['images']->isNotEmpty() || $selection['videos']->isNotEmpty())) {
            $pairs = \App\Services\FacebookAds\FacebookCreativeCopy::fromRows($selection['copy']);
            foreach (['image' => 'images', 'video' => 'videos'] as $type => $group) {
                foreach ($selection[$group] as $asset) {
                    $key = $type.'_ad_'.$asset->id;
                    $metaPairs[$key] = $reviewedPairs[$key] ?? $pairs[count($metaPairs) % count($pairs)];
                }
            }
        }
        $strategy->update(['creative_approval' => [
            'fingerprint' => $selection['fingerprint'],
            ...($selection['legacy'] ? [] : ['allow_variant_rotation' => true, 'mandate' => hash('sha256', json_encode($selection['mandate'], JSON_THROW_ON_ERROR))]),
            'facebook_variants' => $metaPairs, 'copy_digest' => $selection['copy_digest'],
            'copy_ids' => $selection['copy']->pluck('id')->all(),
            'image_ids' => $selection['images']->pluck('id')->all(),
            'google_native_cta' => $selection['google_native_cta'],
            'google_display_banner_hashes' => $strategy->creative_release_review['google_display_banner_hashes'] ?? [],
            'video_ids' => $selection['videos']->pluck('id')->all(),
            'image_hashes' => $strategy->creative_release_review['image_hashes'] ?? [],
            'logo_hashes' => $strategy->creative_release_review['logo_hashes'] ?? [],
            'video_hashes' => $strategy->creative_release_review['video_hashes'] ?? [],
            'approved_at' => now()->toIso8601String(),
        ]]);
    }

    public function isApproved(Strategy $strategy): bool
    {
        $approval = $strategy->creative_approval;

        if (blank($approval['fingerprint'] ?? null) || ($strategy->creative_release_review['status'] ?? null) !== 'passed'
            || $approval['fingerprint'] !== ($strategy->creative_release_review['fingerprint'] ?? null)) {
            return false;
        }
        $selection = $this->selection($strategy);

        return $approval['fingerprint'] === $selection['fingerprint']
            && ($approval['google_native_cta'] ?? null) === $selection['google_native_cta'];
    }

    /**
     * @param  Builder<AdCopy>  $query
     * @return Builder<AdCopy>
     */
    public function approvedCopy(Builder $query, Strategy $strategy): Builder
    {
        return $query->where('should_deploy', true)
            ->when($strategy->creative_approval !== null, fn ($query) => $query->whereKey($strategy->creative_approval['copy_ids']))
            ->orderBy('id');
    }

    public function approvedMedia(Builder $query, Strategy $strategy, string $type): Builder
    {
        return $query->where('is_active', true)->where('should_deploy', true)
            ->when($strategy->creative_approval !== null, fn ($query) => $query->whereKey($strategy->creative_approval[$type.'_ids']))
            ->orderBy('id');
    }

    public function matchesMedia(Strategy $strategy, string $type, int $id, string $bytes): bool
    {
        if ($strategy->creative_approval === null) {
            return false;
        }
        $expected = $strategy->creative_approval[$type.'_hashes'][$id] ?? null;

        return is_string($expected) && hash_equals($expected, hash('sha256', $bytes));
    }
}
