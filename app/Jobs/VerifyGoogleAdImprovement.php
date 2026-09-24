<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use Google\Ads\GoogleAds\V22\Enums\AdStrengthEnum\AdStrength;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class VerifyGoogleAdImprovement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [3600, 7200];

    public $deleteWhenMissingModels = true;

    public function __construct(public Campaign $campaign, public string $adResource, public int $strengthBefore,
        public array $headlines, public array $descriptions) {}

    public function handle(): void
    {
        $customer = $this->campaign->customer;
        if (! $customer) {
            return;
        }
        $reader = app(ReadCampaignConfiguration::class, ['customer' => $customer]);
        $rows = $reader->ads($customer->cleanGoogleCustomerId(), $this->campaign->googleAdsResourceName());
        $row = collect($rows)->first(fn ($row) => ($row['adGroupAd']['resourceName'] ?? '') === $this->adResource);
        $ad = $row['adGroupAd'] ?? [];
        $strength = AdStrength::value($ad['adStrength'] ?? 'UNKNOWN');
        if (in_array($strength, [AdStrength::PENDING, AdStrength::UNKNOWN, AdStrength::UNSPECIFIED], true) && $this->attempts() < 3) {
            $this->release(3600);

            return;
        }
        $actualHeadlines = array_column($ad['ad']['responsiveSearchAd']['headlines'] ?? [], 'text');
        $actualDescriptions = array_column($ad['ad']['responsiveSearchAd']['descriptions'] ?? [], 'text');
        $expectedHeadlines = $this->headlines;
        $expectedDescriptions = $this->descriptions;
        sort($actualHeadlines);
        sort($actualDescriptions);
        sort($expectedHeadlines);
        sort($expectedDescriptions);
        $matched = $actualHeadlines === $expectedHeadlines && $actualDescriptions === $expectedDescriptions;
        $improved = $matched && $strength > $this->strengthBefore;
        AgentActivity::record('quality_score', $improved ? 'ad_strength_improved' : 'ad_copy_update_verified',
            $improved ? 'Google confirmed an improved ad-strength rating.'
                : ($matched ? 'Google confirmed the ad update; no improved strength rating has been confirmed.' : 'The live ad differs from the submitted update; review required.'),
            $customer->id, $this->campaign->id,
            ['ad_resource' => $this->adResource, 'copy_matches' => $matched, 'strength_before' => $this->strengthBefore, 'strength_after' => $strength],
            $matched ? 'completed' : 'needs_review');

        if ($matched) {
            foreach ($this->campaign->strategies as $strategy) {
                $execution = $strategy->execution_result ?? [];
                $changed = false;
                foreach ($execution['metadata']['google_search_baseline']['ads'] ?? [] as $index => $expected) {
                    if (($expected['resource'] ?? '') === $this->adResource) {
                        $execution['metadata']['google_search_baseline']['ads'][$index]['headlines'] = $this->headlines;
                        $execution['metadata']['google_search_baseline']['ads'][$index]['descriptions'] = $this->descriptions;
                        if (isset($expected['ad_copy_id'])) {
                            $strategy->adCopies()->whereKey($expected['ad_copy_id'])->update(['headlines' => $this->headlines, 'descriptions' => $this->descriptions]);
                        }
                        $changed = true;
                    }
                }
                if ($changed) {
                    $strategy->forceFill(['execution_result' => $execution])->save();
                }
            }
        }
    }
}
