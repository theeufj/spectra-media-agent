<?php

namespace App\Jobs;

use App\Jobs\Concerns\RecordsAgentRun;
use App\Models\Campaign;
use App\Services\Agents\GoogleSearchReachRecovery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/** Each campaign has an independent observation/repair job, never a scheduler API call. */
class CheckGoogleSearchDelivery implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsAgentRun, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public int $campaignId) {}

    public function uniqueId(): string
    {
        return 'search-delivery:'.$this->campaignId;
    }

    public function handle(GoogleSearchReachRecovery $recovery): void
    {
        $lock = Cache::lock($this->uniqueId(), 650);
        if (! $lock->get()) {
            return;
        }
        $started = $this->startRun();
        try {
            $campaign = Campaign::withoutCustomerScope()->with('customer')->find($this->campaignId);
            if (! $campaign instanceof Campaign) {
                $this->finishRun($started, scope: "Campaign {$this->campaignId}", note: 'Campaign no longer exists.');

                return;
            }
            $previousStatus = $campaign->search_delivery_state['status'] ?? null;
            $state = $recovery->check($campaign);
            $status = $state['status'];
            $this->finishRun($started,
                actions: $status === 'recovered' && $previousStatus !== 'recovered' ? 1 : 0,
                errors: $status === 'unavailable' ? 1 : 0,
                warnings: in_array($status, ['low_reach', 'approval_required', 'needs_review'], true) ? 1 : 0,
                scope: "Campaign {$campaign->id}",
                note: $status,
                details: ['delivery_status' => $status, 'measurement' => $state['measurement'] ?? [],
                    'blocked_reason' => $state['blocked_reason'] ?? null]);
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordRunFailure($exception);
    }
}
