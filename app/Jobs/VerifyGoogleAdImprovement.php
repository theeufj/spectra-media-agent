<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\GoogleAdStrengthRepair;
use Google\Ads\GoogleAds\V22\Enums\AdStrengthEnum\AdStrength;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class VerifyGoogleAdImprovement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 6;

    public $backoff = [3600, 3600, 7200, 14400, 28800];

    public $deleteWhenMissingModels = true;

    public function __construct(public Campaign $campaign, public string $adResource, public int $strengthBefore,
        public array $headlines, public array $descriptions, public ?int $attemptId = null) {}

    public function handle(): void
    {
        $customer = $this->campaign->customer;
        if (! $customer) {
            return;
        }
        $state = app(GoogleAdStrengthRepair::class);
        $attempt = $this->attempt($state);
        // A later repair or human edit must not be overwritten by a stale verifier.
        if (! $attempt || $state->latest($this->campaign, $this->adResource)?->id !== $attempt->id) {
            return;
        }
        $reader = app(ReadCampaignConfiguration::class, ['customer' => $customer]);
        try {
            $rows = $reader->ads($customer->cleanGoogleCustomerId(), $this->campaign->googleAdsResourceName());
        } catch (\Throwable $e) {
            report($e);
            $attempt->update(['description' => 'Google RSA verification read failed; queued retry remains available.',
                'details' => array_merge($attempt->details ?? [], ['last_read_error' => $e->getMessage(), 'last_read_failed_at' => now()->toIso8601String()])]);
            throw $e;
        }
        $row = collect($rows)->first(fn ($row) => ($row['adGroupAd']['resourceName'] ?? '') === $this->adResource);
        $ad = $row ? GoogleAdStrengthRepair::ad($row) : null;
        $result = $state->verify($this->campaign, $attempt, $ad);
        if ($result['status'] === 'superseded') {
            return;
        }
        if ($result['status'] === 'pending') {
            if ($this->attempts() >= $this->tries) {
                $this->failed(new \RuntimeException('Google has not confirmed the RSA repair after bounded verification attempts.'));
            } else {
                $this->release(3600);
            }

            return;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $state = app(GoogleAdStrengthRepair::class);
        $attempt = $this->attempt($state);
        if (! $attempt || $state->latest($this->campaign, $this->adResource)?->id !== $attempt->id || $attempt->status === 'completed') {
            return;
        }
        $attempt->update(['status' => 'needs_review', 'description' => 'RSA repair could not be verified; review required.',
            'details' => array_merge($attempt->details ?? [], ['reason' => 'verification_failed', 'last_read_error' => $exception->getMessage()])]);
        $state->escalate($this->campaign, $this->adResource, $attempt->description);
    }

    private function attempt(GoogleAdStrengthRepair $state): ?AgentActivity
    {
        if ($this->attemptId !== null) {
            return AgentActivity::where('campaign_id', $this->campaign->id)->findOrFail($this->attemptId);
        }
        // Compatibility for verification jobs already queued before per-ad state.
        $latest = $state->latest($this->campaign, $this->adResource);
        if ($latest && ! $state->sameCopy(['headlines' => $this->headlines, 'descriptions' => $this->descriptions], $latest->details ?? [])) {
            return null;
        }
        if ($latest && $state->sameCopy(['headlines' => $this->headlines, 'descriptions' => $this->descriptions], $latest->details ?? [])) {
            $this->attemptId = $latest->id;

            return $latest;
        }
        $attempt = AgentActivity::record('quality_score', 'ad_copy_update_submitted', 'Verifying an RSA update queued before repair tracking.',
            $this->campaign->customer_id, $this->campaign->id, ['ad_resource' => $this->adResource,
                'strength_before' => AdStrength::name($this->strengthBefore), 'headlines' => $this->headlines, 'descriptions' => $this->descriptions,
                'legacy_reviewed_job' => true, 'submitted_at' => now()->subHour()->toIso8601String(), 'next_verification_at' => now()->toIso8601String(), 'verification_reads' => 0], 'pending');
        $this->attemptId = $attempt->id;

        return $attempt;
    }
}
