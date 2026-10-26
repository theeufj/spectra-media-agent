<?php

namespace App\Jobs;

use App\Jobs\Concerns\RecordsAgentRun;
use App\Services\GoogleAds\ApprovedAiMaxPilotActivation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Wait for Google's new-action propagation; never repeat an unknown campaign write. */
class ActivateApprovedAiMaxPilot implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsAgentRun, SerializesModels;

    public int $tries = 24;

    public int $timeout = 900;

    public int $uniqueFor = 90000;

    public int $backoff = 1800;

    public function __construct(public int $campaignId, public string $nonce) {}

    public function uniqueId(): string
    {
        return 'approved-ai-max:'.$this->campaignId.':'.$this->nonce;
    }

    public function handle(ApprovedAiMaxPilotActivation $activation): void
    {
        $started = $this->startRun();
        try {
            $result = $activation->advance($this->campaignId, $this->nonce);
            $waiting = in_array($result['status'] ?? null, ['awaiting_delivery_validation', 'busy'], true);
            if ($waiting && $this->attempts() >= $this->tries) {
                $result = $activation->exhaust($this->campaignId, $this->nonce);
                $waiting = false;
            }
            $this->finishRun($started, actions: (int) ($result['actions_taken'] ?? 0),
                warnings: $waiting || ($result['status'] ?? null) === 'needs_review' ? 1 : 0,
                scope: 'Campaign '.$this->campaignId, note: $result['message'] ?? null, details: $result);
            if ($waiting) {
                $this->release((int) ($result['retry_after_seconds'] ?? 1800));
            }
        } catch (\Throwable $e) {
            report($e);
            $this->finishRun($started, errors: 1, scope: 'Campaign '.$this->campaignId,
                note: 'Owner-approved AI Max activation needs review; no unknown write will be replayed.');
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordRunFailure($exception);
        try {
            app(ApprovedAiMaxPilotActivation::class)->exhaust($this->campaignId, $this->nonce);
        } catch (\Throwable $stopError) {
            report($stopError);
        }
    }
}
